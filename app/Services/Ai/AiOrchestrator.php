<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Orchestrates a single voice-or-text command through the full pipeline:
 *   1. (optional) Whisper → text
 *   2. LLM → tool call
 *   3. Tool execution against the ERP
 *   4. LLM → final natural-language response
 */
class AiOrchestrator
{
    public function __construct(
        private readonly HuggingFaceClient  $hf,
        private readonly ToolRegistry       $tools,
        private readonly LocalFallbackEngine $fallback,
    ) {}

    private const SYSTEM_PROMPT = <<<TXT
Tu es l'assistant intégré de Qiwam ERP — nommé "Waxal" (de l'expression Wolof "waxal" qui signifie "parle").

RÈGLES STRICTES :
- Tu réponds TOUJOURS en français, de manière concise et professionnelle.
- Tu utilises UNIQUEMENT le mécanisme officiel de tool calling (champ `tool_calls` de la réponse). Tu n'écris JAMAIS de syntaxe d'appel de fonction dans le texte (interdit : <function=...>, <tool_call>, JSON entre balises, etc.).
- Si une question peut être résolue par un outil disponible, tu APPELLES l'outil — tu ne demandes pas à l'utilisateur de le faire à ta place.
- Tu n'inventes jamais de produits, de SKU, de chiffres ni de noms de clients : seules les données retournées par les outils sont vraies.
- Si un outil échoue ou ne renvoie rien, dis-le clairement à l'utilisateur en une phrase.
- Pour les actions de modification (ajout de stock, etc.), confirme avec les chiffres exacts retournés par l'outil.

FORMATAGE DES RÉPONSES :
- Pour 1 ou 2 éléments → 1 phrase courte.
- Pour 3+ éléments → réponse structurée OBLIGATOIRE :
    1. Une phrase d'introduction très courte (ex: "Voici tes 6 matières premières :")
    2. Une ligne par élément, préfixée par "• " (puce Unicode + espace)
    3. Mets en gras les noms (avec **double astérisques**) si pertinent
    4. Met l'unité, le stock ou le prix entre parenthèses
- Utilise toujours `\n` pour séparer les lignes (jamais tout sur une ligne).

OUTILS DISPONIBLES :

📦 Catalogue & Stock
- list_products      → demande portant sur TOUS les produits (liste, nombre, combien, quels produits)
                       EXEMPLES : "liste mes produits", "j'ai combien de produits ?",
                                  "quels sont mes produits ?", "montre-moi les produits",
                                  "gnata produit", "produits yi", "show products"
- list_materials     → demande portant sur TOUTES les matières premières / ingrédients
                       EXEMPLES : "liste mes matières premières", "mes ingrédients",
                                  "matières premières disponibles"
- list_low_stock     → "stock faible", "rupture", "produits qui manquent", "amul ci stock"
- query_stock        → stock d'UN SEUL produit précis nommé explicitement
                       EXEMPLES : "combien de Farine ?", "stock du Sucre ?", "il reste combien de Riz ?"
                       ⚠️  N'utilise PAS query_stock si l'utilisateur ne donne PAS un nom de produit.
- create_product     → "ajoute / crée le produit X au prix de Y" (un seul item)
- bulk_create_products → quand l'utilisateur fournit une LISTE / TABLEAU / CSV.
- add_stock_movement → "ajoute / retire N unités de X au stock"

Production & Recettes (BOM)
- list_boms          → "liste mes recettes", "quelles BOMs ai-je ?"
- query_bom          → "détails de la recette X"
- launch_production  → "lance une production de N de X"

Finance & Dépenses
- create_expense     → "enregistre une dépense de N pour X", "payé 5000 pour loyer"
- list_expenses      → "combien j'ai dépensé ?", "mes dépenses de ce mois"
- bulk_create_expenses → pour importer une liste de dépenses d'un coup.

Commerce & CRM
- list_orders        → "combien j'ai vendu ?", "chiffre d'affaires", "ventes du jour / mois"
                       EXEMPLES : "combien j'ai vendu aujourd'hui ?", "ventes de ce mois",
                                  "xaalis bi tëy", "jaay bi tëy"
- query_order        → "détails de la commande CMD-XXX", "statut de la vente Y"
- list_customers     → "combien de clients ?", "j'ai combien de clients ?", "liste clients",
                       "trouve le client X", "nit yi", "client yi", "gnata client"
- create_customer    → "ajoute un client nommé X au numéro Y"

═══════════════════════════════════════════════════════
RÈGLE ABSOLUE — DISTINCTIONS CRITIQUES :
═══════════════════════════════════════════════════════

"j'ai combien de produits ?"          → list_products   ← PAS query_stock
"combien de produits j'ai ?"          → list_products   ← PAS query_stock
"liste mes produits"                  → list_products
"quels sont mes produits ?"           → list_products
"j'ai combien de clients ?"           → list_customers  ← PAS query_stock
"combien de clients ?"                → list_customers
"combien j'ai vendu ?"                → list_orders
"combien il reste de Farine ?"        → query_stock (produit = "Farine")
"stock du Riz ?"                      → query_stock (produit = "Riz")
"ajoute le produit X à 600 FCFA"      → create_product (type=product)
"ajoute la matière X à 800 FCFA/kg"  → create_product (type=material)
"ajoute 50 kg de X au stock"          → add_stock_movement
"enregistre 5000 pour le loyer"       → create_expense
"liste mes matières premières"        → list_materials (PAS list_products)
"lance une production de 30 X"        → launch_production

query_stock UNIQUEMENT si un nom de produit précis est mentionné.
Si l'utilisateur dit "produits" sans nom précis → list_products.
Si l'utilisateur dit "clients" sans nom précis → list_customers.

SUPPORT WOLOF (Waxal) :
L'utilisateur peut parler en Wolof ou en mélangeant Wolof et français.
Mots-clés Wolof à reconnaître :

- "gnata" / "nata"        = combien → question de quantité sur TOUT (liste, pas un seul)
- "gnata produit"         → list_products
- "gnata client"          → list_customers
- "gnata commande"        → list_orders
- "jënd" / "jend"         = acheter / achat
- "jaay" / "jaaye"        = vendre / vente
- "xaalis"                = argent / montant / chiffre d'affaires
- "xaalis bi tëy"         → list_orders (CA du jour)
- "jaay bi tëy"           → list_orders (ventes du jour)
- "client yi" / "nit yi"  → list_customers
- "produit yi"            → list_products
- "stock bi"              = le stock (stock en général)
- "amul"                  = il n'y a pas / rupture → list_low_stock
- "am na"                 = il y a / disponible
- "tëy"                   = aujourd'hui
- "lewet bi" / "ci weer bi" = ce mois

Exemples Wolof → outil :
- "gnata produit la am si stock ?" → list_products
- "gnata client la am ?"           → list_customers
- "jaay bi tëy yombu ?"            → list_orders
- "xaalis bi tëy ?"                → list_orders
- "client yi lañu?"                → list_customers
- "produit yi lañu?"               → list_products
- "amul ci stock"                  → list_low_stock
- "jënd naa essence 5000 FCFA"     → create_expense

Si la demande ne correspond à aucun outil, réponds en français en 1-2 phrases sans inventer de données.
TXT;

    /**
     * Runs a text command through the LLM and resolves any tool call.
     *
     * @param  bool  $isVoice  Si true, utilise le modèle léger (plus haut TPM Groq)
     * @return array{transcript:string, action:string, tool_result:?array, reply:string}
     */
    public function handleText(string $userText, bool $isVoice = false): array
    {
        $now = now()->format('d/m/Y H:i:s');
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT . "\n\nDATE ACTUELLE : {$now}"],
            ['role' => 'user',   'content' => $userText],
        ];

        try {
            $first = $this->hf->chat($messages, $this->tools->schemas(), 0.2, $isVoice);
        } catch (\Throwable $e) {
            // Groq indisponible (rate limit, timeout, erreur réseau…) → fallback local
            Log::info('[AI] Groq unavailable — basculement fallback local', ['reason' => $e->getMessage()]);
            return $this->fallback->handle($userText);
        }
        $msg   = $first['choices'][0]['message'] ?? [];

        $toolCalls = $msg['tool_calls'] ?? [];

        // Heuristic: Some smaller models (like Llama 8B) output JSON as plain text
        // instead of using the formal tool_calls field.
        if (empty($toolCalls) && ! empty($msg['content'])) {
            $content = trim($msg['content']);
            if (str_starts_with($content, '{') && str_ends_with($content, '}')) {
                $decoded = json_decode($content, true);
                if (isset($decoded['name']) || isset($decoded['function']['name'])) {
                    $toolCalls = [[
                        'id'       => 'call_' . uniqid(),
                        'type'     => 'function',
                        'function' => [
                            'name'      => $decoded['name'] ?? $decoded['function']['name'],
                            'arguments' => $decoded['arguments'] ?? $decoded['function']['arguments'] ?? $decoded['parameters'] ?? '{}',
                        ]
                    ]];
                }
            }
        }

        $toolResult = null;
        $action = 'none';

        if (! empty($toolCalls)) {
            // Use the first tool call (single-turn for now)
            $call     = $toolCalls[0];
            $toolName = $call['function']['name'] ?? '';
            $rawArgs  = $call['function']['arguments'] ?? '{}';
            $args     = is_string($rawArgs) ? json_decode($rawArgs, true) : (array) $rawArgs;

            $tool = $this->tools->get($toolName);

            if (! $tool) {
                $toolResult = ['ok' => false, 'error' => "Outil inconnu : {$toolName}"];
            } else {
                try {
                    $toolResult = $tool->execute($args ?? []);
                    $action = $toolName;
                } catch (\Throwable $e) {
                    Log::error('[AI] Tool execution failed', [
                        'tool' => $toolName, 'error' => $e->getMessage(),
                    ]);
                    $toolResult = ['ok' => false, 'error' => 'Échec interne : '.$e->getMessage()];
                }
            }

            // Second turn — feed the tool's result back to the LLM for a NL response
            // We strip the content from the first message if it was a heuristic match
            // to avoid confusing the model with its own leaked JSON.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $toolCalls,
            ];

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'] ?? '',
                'name'         => $toolName,
                'content'      => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];

            try {
                $second = $this->hf->chat($messages, null, 0.2, $isVoice);
                $reply  = (string) ($second['choices'][0]['message']['content']
                                    ?? $toolResult['message']
                                    ?? 'Action effectuée.');
            } catch (\Throwable $e) {
                $reply = $toolResult['message'] ?? 'Action effectuée (mais erreur de réponse NL).';
            }
        } else {
            $reply = (string) ($msg['content'] ?? '');
        }

        return [
            'transcript'  => $userText,
            'action'      => $action,
            'tool_result' => $toolResult,
            'reply'       => $this->sanitizeReply($reply),
        ];
    }

    /**
     * Strips any function-call syntax that the LLM might leak into the textual reply.
     * Some models (Llama family in particular) occasionally emit
     * <function=name>{...}</function> or <tool_call>{...}</tool_call> as text
     * instead of through the proper `tool_calls` channel.
     */
    private function sanitizeReply(string $reply): string
    {
        $patterns = [
            '/<function[^>]*>.*?<\/function>/is',
            '/<tool_call[^>]*>.*?<\/tool_call>/is',
            '/<\|python_tag\|>.*?(?=<\||$)/is',
            '/<\|tool_call_start\|>.*?<\|tool_call_end\|>/is',
        ];

        $cleaned = preg_replace($patterns, '', $reply) ?? $reply;
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned) ?? $cleaned;
        $cleaned = trim($cleaned);

        // If sanitisation killed everything, give the user a graceful fallback
        if ($cleaned === '') {
            return "Je n'ai pas pu formuler une réponse. Reformule la question, s'il te plaît.";
        }

        return $cleaned;
    }

    /**
     * Transcribes audio then runs the full text pipeline.
     *
     * @param  string  $language  'fr' (défaut) | 'wo' (Wolof via Waxal)
     * @return array{transcript:string, action:string, tool_result:?array, reply:string, language:string}
     */
    public function handleVoice(string $audioPath, string $language = 'fr'): array
    {
        // ── Transcription ─────────────────────────────────────────────────────
        try {
            if ($language === 'wo') {
                // Waxal path — Hugging Face Inference Providers
                $whisper = $this->hf->transcribeWolof($audioPath);
                Log::info('[Waxal] Transcription Wolof OK', ['text_preview' => mb_substr($whisper['text'] ?? '', 0, 60)]);
            } else {
                // Groq Whisper (français / autres langues)
                $whisper = $this->hf->transcribe($audioPath, $language);
            }
        } catch (\Throwable $e) {
            // Si Waxal/HF échoue, on tente avec Groq en mode 'fr' en fallback
            Log::warning('[Waxal] Transcription failed, trying Groq fallback', ['err' => $e->getMessage()]);
            try {
                $whisper = $this->hf->transcribe($audioPath, 'fr');
            } catch (\Throwable $e2) {
                return [
                    'transcript'  => '',
                    'action'      => 'none',
                    'tool_result' => null,
                    'reply'       => "Je n'ai pas pu transcrire l'audio. Vérifie ta connexion et réessaie.",
                    'language'    => $language,
                ];
            }
        }

        $text = (string) ($whisper['text'] ?? '');

        if (trim($text) === '') {
            return [
                'transcript'  => '',
                'action'      => 'none',
                'tool_result' => null,
                'reply'       => $language === 'wo'
                    ? "Amul ci audio bi. Réessaye — wax ak kaw."
                    : "Je n'ai rien compris. Peux-tu réessayer ?",
                'language'    => $language,
            ];
        }

        // isVoice=true → utilise llama-3.1-8b-instant (20k TPM vs 12k pour le 70B)
        $result = $this->handleText($text, true);
        $result['language'] = $language;
        return $result;
    }
}
