<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around any OpenAI-compatible AI provider.
 *
 * Configured par défaut pour Groq (gratuit, généreux, rapide) :
 *   AI_BASE_URL = https://api.groq.com/openai/v1
 *   AI_TOKEN    = gsk_xxxx
 *
 * Compatible aussi avec Hugging Face Inference Providers, OpenRouter, etc.
 *
 * Deux endpoints :
 *   - Chat Completions   → /chat/completions  (LLM + tool calling)
 *   - Audio Transcription → /audio/transcriptions (Whisper)
 */
class HuggingFaceClient
{
    // ── LLM (chat + tool calling) ─────────────────────────────────────────────
    private string $token;
    private string $chatModel;
    private string $voiceModel;
    private string $baseUrl;

    // ── Whisper (transcription audio) — provider séparé possible ─────────────
    // Si AI_WHISPER_TOKEN est défini, Whisper utilise son propre provider.
    // Sinon, il hérite du token LLM (si le provider supporte Whisper).
    private string $whisperToken;
    private string $whisperModel;
    private string $whisperBaseUrl;

    private int $timeout;
    private int $maxRetries;
    private int $retryBuffer;

    public function __construct()
    {
        // ── LLM provider (Cerebras, Groq, OpenRouter, HF…) ───────────────────
        $this->token     = (string) (config('ai.token') ?: config('ai.hf_token'));
        $this->baseUrl   = rtrim((string) (config('ai.base_url') ?: config('ai.hf_base_url', 'https://api.groq.com/openai/v1')), '/');
        $this->chatModel  = (string) config('ai.chat_model',  'llama-3.3-70b');
        $this->voiceModel = (string) config('ai.voice_model', 'llama3.1-8b');

        // ── Whisper provider — peut être différent du LLM (ex: Groq pour Whisper) ──
        // Si AI_WHISPER_TOKEN n'est pas défini, on tente avec le token LLM.
        // Si AI_WHISPER_BASE_URL n'est pas défini, on utilise Groq (seul provider
        // public gratuit qui expose Whisper en API OpenAI-compatible).
        $this->whisperToken   = (string) (config('ai.whisper_token')    ?: config('ai.token') ?: config('ai.hf_token'));
        $this->whisperBaseUrl = rtrim((string) (config('ai.whisper_base_url') ?: 'https://api.groq.com/openai/v1'), '/');
        $this->whisperModel   = (string) config('ai.whisper_model', 'whisper-large-v3');

        $this->timeout     = (int) config('ai.timeout', 60);
        $this->maxRetries  = (int) config('ai.max_retries',    2);
        $this->retryBuffer = (int) config('ai.retry_buffer_s', 1);
    }

    /**
     * Envoie une requête de complétion de chat avec tool-calling optionnel.
     *
     * @param  array<int, array<string, mixed>>       $messages
     * @param  array<int, array<string, mixed>>|null  $tools
     * @param  bool  $useVoiceModel  Utilise le modèle léger (plus haut TPM) pour la voix
     * @return array<string, mixed>
     */
    public function chat(array $messages, ?array $tools = null, float $temperature = 0.2, bool $useVoiceModel = false): array
    {
        $model = $useVoiceModel ? $this->voiceModel : $this->chatModel;

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => 1024,
        ];

        if (! empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($this->token)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post("{$this->baseUrl}/chat/completions", $payload);

        // 429 Rate limit → exception dédiée pour déclencher le fallback local immédiatement
        if ($response->status() === 429) {
            $retryAfter = $this->extractRetryAfter($response->body());
            Log::warning('[AI] Rate limit 429', ['model' => $model, 'retry_after' => $retryAfter . 's']);
            throw new \App\Services\Ai\Exceptions\AiRateLimitException(
                "Rate limit Groq ({$model}). Retry après {$retryAfter}s.",
                $retryAfter
            );
        }

        return $this->handleJsonResponse($response, 'chat');
    }

    /**
     * Extrait le délai de retry depuis le message d'erreur Groq.
     * Ex : "Please try again in 4.435s." → 4.435
     */
    private function extractRetryAfter(string $body): float
    {
        if (preg_match('/try again in ([\d.]+)s/i', $body, $m)) {
            return (float) $m[1];
        }
        return 5.0;
    }

    /**
     * Transcrit un fichier audio avec Whisper via l'endpoint OpenAI-compatible.
     *
     * Groq supporte : webm, mp3, mp4, wav, ogg, flac, m4a
     *
     * @return array{text: string}
     */
    public function transcribe(string $audioPath, string $language = 'fr'): array
    {
        $audioBytes = file_get_contents($audioPath);

        $response = Http::withToken($this->whisperToken)
            ->timeout($this->timeout)
            ->attach('file', $audioBytes, 'recording.webm')
            ->post("{$this->whisperBaseUrl}/audio/transcriptions", [
                'model'           => $this->whisperModel,
                'language'        => $language,
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            Log::warning('[AI] Whisper failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException(
                "Whisper API error: HTTP {$response->status()} — {$response->body()}"
            );
        }

        $json = $response->json() ?? [];
        return ['text' => (string) ($json['text'] ?? '')];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleJsonResponse(Response $response, string $context): array
    {
        if (! $response->successful()) {
            Log::warning('[AI] HF call failed', [
                'context' => $context,
                'status'  => $response->status(),
                'body'    => mb_substr($response->body(), 0, 500),
            ]);

            throw new \RuntimeException(
                "AI API error ({$context}): HTTP {$response->status()}"
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException("Invalid JSON from AI provider ({$context})");
        }

        return $json;
    }
}
