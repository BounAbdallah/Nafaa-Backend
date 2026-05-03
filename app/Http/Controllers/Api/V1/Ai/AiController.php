<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Voice + chat interface for Qiwam ERP.
 *
 * Endpoints:
 *   POST /api/v1/ai/text   { message: "..." }
 *   POST /api/v1/ai/voice  multipart audio (field "audio")
 *   GET  /api/v1/ai/tools  → list of available capabilities (debug/UI hints)
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiOrchestrator $orchestrator,
        private readonly ToolRegistry $registry,
    ) {}

    public function text(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        try {
            $result = $this->orchestrator->handleText($data['message']);
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[AI] /text failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Le service IA est temporairement indisponible.',
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 503);
        }
    }

    public function voice(Request $request): JsonResponse
    {
        $request->validate([
            'audio' => [
                'required',
                'file',
                'max:'.config('ai.max_audio_kb', 5120),
                'mimetypes:audio/webm,audio/mpeg,audio/mp4,audio/ogg,audio/wav,audio/x-m4a',
            ],
        ]);

        $tmpPath = $request->file('audio')->getRealPath();

        try {
            $result = $this->orchestrator->handleVoice($tmpPath);
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('[AI] /voice failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error'  => 'Échec du traitement vocal.',
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 503);
        }
    }

    public function tools(): JsonResponse
    {
        return response()->json([
            'tools' => array_map(
                fn ($t) => [
                    'name'        => $t['function']['name']        ?? null,
                    'description' => $t['function']['description'] ?? null,
                ],
                $this->registry->schemas(),
            ),
        ]);
    }
}
