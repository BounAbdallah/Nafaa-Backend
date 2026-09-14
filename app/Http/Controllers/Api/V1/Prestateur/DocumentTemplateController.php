<?php
namespace App\Http\Controllers\Api\V1\Prestateur;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Services\Ai\HuggingFaceClient;
use Illuminate\Http\{JsonResponse, Request};
use Smalot\PdfParser\Parser as PdfParser;

class DocumentTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = DocumentTemplate::where('tenant_id', $request->user()->tenant_id)
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->orderBy('name')
            ->get();
        return response()->json($templates);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'type'        => 'required|in:quote,contract,invoice',
            'content'     => 'required|string',
            'description' => 'nullable|string',
            'is_default'  => 'boolean',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;

        if (!empty($data['is_default'])) {
            DocumentTemplate::where('tenant_id', $data['tenant_id'])
                ->where('type', $data['type'])
                ->update(['is_default' => false]);
        }

        return response()->json(DocumentTemplate::create($data), 201);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        abort_if($documentTemplate->tenant_id !== $request->user()->tenant_id, 403);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'content'     => 'sometimes|string',
            'description' => 'nullable|string',
            'is_default'  => 'boolean',
        ]);
        if (!empty($data['is_default'])) {
            DocumentTemplate::where('tenant_id', $documentTemplate->tenant_id)
                ->where('type', $documentTemplate->type)
                ->where('id', '!=', $documentTemplate->id)
                ->update(['is_default' => false]);
        }
        $documentTemplate->update($data);
        return response()->json($documentTemplate);
    }

    public function destroy(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        abort_if($documentTemplate->tenant_id !== $request->user()->tenant_id, 403);
        $documentTemplate->delete();
        return response()->json(null, 204);
    }

    /**
     * Import PDF → extraction texte → IA → template TipTap HTML
     */
    public function importPdf(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimetypes:application/pdf|max:10240',
            'type' => 'required|in:quote,contract,invoice',
            'name' => 'required|string|max:255',
        ]);

        // 1. Extraire le texte du PDF
        try {
            $parser   = new PdfParser();
            $pdf      = $parser->parseFile($request->file('file')->getRealPath());
            $rawText  = $pdf->getText();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Impossible de lire le PDF : ' . $e->getMessage()], 422);
        }

        if (trim($rawText) === '') {
            return response()->json(['error' => 'PDF vide ou protégé — aucun texte extrait.'], 422);
        }

        // 2. IA → structurer en template avec variables
        $typeLabel = match($request->type) {
            'quote'    => 'devis',
            'contract' => 'contrat',
            'invoice'  => 'facture',
        };

        $prompt = <<<PROMPT
Tu reçois le texte brut d'un {$typeLabel} PDF extrait automatiquement.

Ta mission :
1. Nettoie et structure ce texte en HTML propre (h1, h2, p, ul, table si nécessaire).
2. Identifie toutes les données variables (noms de personnes, entreprises, dates, montants, adresses, numéros de référence, durées) et remplace-les par des variables entre doubles accolades.
   Exemples de variables : {{nom_client}}, {{adresse_client}}, {{date_signature}}, {{montant_total}}, {{reference}}, {{nom_prestataire}}, {{date_debut}}, {{date_fin}}
3. Garde le reste du texte fixe (clauses légales, descriptions de services, etc.).
4. Retourne UNIQUEMENT le HTML résultant, sans commentaire ni balise <html>/<body>.

Texte brut du {$typeLabel} :
---
{$rawText}
---
PROMPT;

        try {
            $ai     = app(HuggingFaceClient::class);
            $result = $ai->chat([
                ['role' => 'system', 'content' => 'Tu es un expert en rédaction de documents professionnels. Tu retournes uniquement du HTML propre.'],
                ['role' => 'user',   'content' => $prompt],
            ], null, 0.1);
            $html = trim($result['choices'][0]['message']['content'] ?? '');
        } catch (\Throwable $e) {
            // Fallback : retourner le texte brut formaté si l'IA échoue
            $html = '<p>' . nl2br(htmlspecialchars($rawText)) . '</p>';
        }

        // 3. Sauvegarder le template
        $template = DocumentTemplate::create([
            'tenant_id'   => $request->user()->tenant_id,
            'name'        => $request->name,
            'type'        => $request->type,
            'content'     => $html,
            'description' => 'Importé depuis PDF',
            'is_default'  => false,
        ]);

        return response()->json([
            'template' => $template,
            'preview'  => mb_substr($html, 0, 300) . '…',
        ], 201);
    }
}
