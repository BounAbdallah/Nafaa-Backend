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
- Tu réponds toujours en français, de manière concise et professionnelle.
- Tu utilises les outils disponibles pour modifier ou interroger l'ERP.
- Tu n'inventes jamais de produits ou de chiffres : si l'outil échoue, tu le dis clairement à l'utilisateur.
- Pour les actions de modification (ajout de stock, etc.), confirme l'action effectuée avec les chiffres exacts retournés par l'outil.
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
            'reply'       => $reply,
        ];
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
