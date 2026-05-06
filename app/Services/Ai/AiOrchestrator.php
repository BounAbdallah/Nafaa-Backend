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
Tu es l'assistant intégré de Qiwam ERP.

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
- create_product     → "ajoute / crée le produit X au prix de Y" (un seul item)
                       → pour une matière première : type=material (et selling_price=0 si pas vendue)
                       → pour un service : type=service
- bulk_create_products → quand l'utilisateur fournit une LISTE / TABLEAU / CSV à insérer.
- list_products      → "liste mes produits (finis)", "trouve les produits X"
- list_materials     → "liste mes matières premières", "mes ingrédients"
- list_low_stock     → "quels produits sont en stock faible / bas / rupture ?"
- query_stock        → "stock du produit/matière X ?", "combien il reste de Y ?"
- add_stock_movement → "ajoute / retire N unités de X au stock"

Production & Recettes (BOM)
- list_boms          → "liste mes recettes", "quelles BOMs ai-je ?"
- query_bom          → "détails de la recette X", "rentabilité de la recette Y"
- launch_production  → "lance une production de N de X", "fabrique N X"

Finance & Dépenses
- create_expense     → "enregistre une dépense de N pour X", "payé 5000 pour loyer"
- list_expenses      → "combien j'ai dépensé ?", "mes dépenses de ce mois"
- bulk_create_expenses → pour importer une liste de dépenses d'un coup.

Commerce & CRM
- list_orders        → "combien j'ai vendu aujourd'hui ?", "liste les ventes de Mai"
- query_order        → "détails de la commande X", "statut de la vente Y"
- list_customers     → "trouve le client X", "donne-moi le numéro de Y", "meilleurs clients"
- create_customer    → "ajoute un client nommé X au numéro Y"

Distinctions critiques (chaque exemple → 1 seul outil) :
- "ajoute le produit X à 600 FCFA"               → create_product (type=product)
- "ajoute la matière première X à 800 FCFA/kg"  → create_product (type=material, unit=kg)
- "ajoute 50 kg de X au stock"                   → add_stock_movement (mouvement)
- "enregistre 5000 pour le loyer"                → create_expense (PAS create_product)
- "combien j'ai vendu aujourd'hui ?"             → list_orders
- "liste mes matières premières"                 → list_materials (PAS list_products)
- "lance une production de 30 plats de X"        → launch_production

Si la demande de l'utilisateur ne correspond à aucun outil, réponds simplement en français en 1-2 phrases sans inventer de données.
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
     * Utilise le modèle léger (voice_model) pour avoir plus de TPM disponibles.
     */
    public function handleVoice(string $audioPath): array
    {
        $whisper = $this->hf->transcribe($audioPath, 'fr');
        $text    = (string) ($whisper['text'] ?? '');

        if (trim($text) === '') {
            return [
                'transcript'  => '',
                'action'      => 'none',
                'tool_result' => null,
                'reply'       => "Je n'ai rien compris. Peux-tu réessayer ?",
            ];
        }

        // isVoice=true → utilise llama-3.1-8b-instant (20k TPM vs 12k pour le 70B)
        return $this->handleText($text, true);
    }
}
