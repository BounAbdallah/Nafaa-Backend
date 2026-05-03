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
        private readonly HuggingFaceClient $hf,
        private readonly ToolRegistry $tools,
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

EXEMPLE ATTENDU (matières premières) :
Voici tes 6 matières premières :
• **Riz** — 47 kg en stock (300 FCFA/kg)
• **Poulet** — 11 kg en stock (3 500 FCFA/kg)
• **Oignon** — 14 kg en stock
• **Huile** — 20 litres en stock
• **Sel** — 440 g en stock
• **Épices** — 1 991 g en stock

OUTILS DISPONIBLES :

📦 Catalogue & Stock
- create_product     → "ajoute / crée le produit X au prix de Y"
                       → pour une matière première : passer type=material (et selling_price=0 si pas vendue)
                       → pour un service : passer type=service
- list_products      → "liste mes produits (finis)", "trouve les produits X"
- list_materials     → "liste mes matières premières", "mes ingrédients", "matières en stock faible"
- list_low_stock     → "quels produits sont en stock faible / bas / rupture ?"
- query_stock        → "stock du produit/matière X ?", "combien il reste de Y ?"
- add_stock_movement → "ajoute / retire N unités de X au stock"

🏭 Production & Recettes (BOM)
- list_boms          → "liste mes recettes", "quelles BOMs ai-je ?"
- query_bom          → "détails de la recette X", "rentabilité de la recette Y", "combien coûte X à produire ?"
- launch_production  → "lance une production de N de X", "fabrique N X", "crée un OF de N X"

⚠️ Distinctions critiques (chaque exemple → 1 seul outil) :
- "ajoute le produit X à 600 FCFA"               → create_product (type=product)
- "ajoute la matière première X à 800 FCFA/kg"  → create_product (type=material, unit=kg)
- "ajoute le service X à 1500"                   → create_product (type=service)
- "ajoute 50 kg de X au stock"                   → add_stock_movement (mouvement)
- "liste mes matières premières"                 → list_materials (PAS list_products)
- "liste mes produits finis"                     → list_products
- "lance une production de 30 plats de X"        → launch_production
- "détails de la recette X"                      → query_bom

Si la demande de l'utilisateur ne correspond à aucun outil, réponds simplement en français en 1-2 phrases sans inventer de données.
TXT;

    /**
     * Runs a text command through the LLM and resolves any tool call.
     *
     * @return array{transcript:string, action:string, tool_result:?array, reply:string}
     */
    public function handleText(string $userText): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => $userText],
        ];

        $first = $this->hf->chat($messages, $this->tools->schemas());
        $msg   = $first['choices'][0]['message'] ?? [];

        $toolCalls = $msg['tool_calls'] ?? [];
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
            $messages[] = $msg;
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $call['id'] ?? '',
                'name'         => $toolName,
                'content'      => json_encode($toolResult, JSON_UNESCAPED_UNICODE),
            ];

            $second = $this->hf->chat($messages); // no tools on the wrap-up turn
            $reply  = (string) ($second['choices'][0]['message']['content']
                                ?? $toolResult['message']
                                ?? 'Action effectuée.');
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

        return $this->handleText($text);
    }
}
