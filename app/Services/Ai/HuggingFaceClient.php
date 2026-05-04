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
    private string $token;
    private string $chatModel;
    private string $whisperModel;
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        // Priorité à AI_TOKEN / AI_BASE_URL (Groq par défaut).
        // Rétrocompat avec HF_TOKEN / HF_BASE_URL si pas encore migré.
        $this->token        = (string) (config('ai.token') ?: config('ai.hf_token'));
        $this->baseUrl      = rtrim((string) (config('ai.base_url') ?: config('ai.hf_base_url', 'https://api.groq.com/openai/v1')), '/');
        $this->chatModel    = (string) config('ai.chat_model',    'llama-3.3-70b-versatile');
        $this->whisperModel = (string) config('ai.whisper_model', 'whisper-large-v3');
        $this->timeout      = (int)    config('ai.timeout', 60);
    }

    /**
     * Envoie une requête de complétion de chat avec tool-calling optionnel.
     *
     * @param  array<int, array<string, mixed>>       $messages
     * @param  array<int, array<string, mixed>>|null  $tools
     * @return array<string, mixed>
     */
    public function chat(array $messages, ?array $tools = null, float $temperature = 0.2): array
    {
        $payload = [
            'model'       => $this->chatModel,
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

        return $this->handleJsonResponse($response, 'chat');
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

        $response = Http::withToken($this->token)
            ->timeout($this->timeout)
            ->attach('file', $audioBytes, 'recording.webm')
            ->post("{$this->baseUrl}/audio/transcriptions", [
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
