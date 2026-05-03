<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Hugging Face Inference API.
 *
 * Free tier docs: https://huggingface.co/docs/inference-providers
 *
 * Uses two endpoints:
 *   - Chat Completions (OpenAI-compatible) for the LLM
 *   - Audio Transcription (Whisper) for speech-to-text
 */
class HuggingFaceClient
{
    private string $token;
    private string $chatModel;
    private string $whisperModel;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->token        = (string) config('ai.hf_token');
        $this->chatModel    = (string) config('ai.chat_model',    'meta-llama/Llama-3.3-70B-Instruct');
        $this->whisperModel = (string) config('ai.whisper_model', 'openai/whisper-large-v3-turbo');
        $this->baseUrl      = rtrim((string) config('ai.hf_base_url', 'https://router.huggingface.co/v1'), '/');
        $this->timeout      = (int) config('ai.timeout', 60);
    }

    /**
     * Sends a chat completion request with optional tools (function calling).
     *
     * @param  array<int, array<string, mixed>>  $messages
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
     * Transcribes an audio file with Whisper.
     *
     * @return array<string, mixed>
     */
    public function transcribe(string $audioPath, string $language = 'fr'): array
    {
        // Whisper endpoint = direct model inference (not OpenAI-compatible)
        $url = "https://api-inference.huggingface.co/models/{$this->whisperModel}";

        $response = Http::withToken($this->token)
            ->timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'audio/webm',
                'x-wait-for-model' => 'true',
            ])
            ->withBody(file_get_contents($audioPath), 'audio/webm')
            ->post($url . '?language=' . $language);

        return $this->handleJsonResponse($response, 'whisper');
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
                "Hugging Face API error ({$context}): HTTP {$response->status()}"
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException("Invalid JSON from HF ({$context})");
        }

        return $json;
    }
}
