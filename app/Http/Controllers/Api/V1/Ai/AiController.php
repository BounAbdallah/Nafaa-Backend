<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiOrchestrator;
use App\Services\Ai\CsvParser;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Tools\BulkCreateProductsTool;
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
        // Note: MediaRecorder webm/opus is sometimes detected as video/webm by
        // Laravel's MIME guesser (the webm container is shared with video).
        // We accept both, plus all common audio formats, and fall back to a
        // permissive size+extension check.
        $request->validate([
            'audio' => [
                'required',
                'file',
                'max:'.config('ai.max_audio_kb', 5120),
                'mimetypes:'.implode(',', [
                    'audio/webm', 'audio/mpeg', 'audio/mp4', 'audio/ogg',
                    'audio/wav',  'audio/x-m4a','audio/flac','audio/aac',
                    'video/webm', 'video/mp4', 'application/octet-stream',
                ]),
            ],
        ]);

        $file    = $request->file('audio');
        $tmpPath = $file->getRealPath();

        Log::debug('[AI] /voice incoming', [
            'mime'     => $file->getMimeType(),
            'ext'      => $file->getClientOriginalExtension(),
            'size_kb'  => round($file->getSize() / 1024, 1),
        ]);

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

    /**
     * CSV import → bulk product creation.
     * Parses the file with CsvParser then delegates to BulkCreateProductsTool.
     */
    public function importCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:2048', // 2 MB
                'mimetypes:text/csv,text/plain,application/vnd.ms-excel,application/csv',
            ],
            'default_type' => ['nullable', 'in:product,service,material'],
        ]);

        $defaultType = (string) ($request->input('default_type') ?? 'material');

        try {
            $contents = file_get_contents($request->file('file')->getRealPath());

            $items = (new CsvParser())->parse($contents);

            if (empty($items)) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'CSV vide ou non reconnu. Vérifie l\'en-tête (Nom, Catégorie, Prix d\'achat, Stock…).',
                ], 422);
            }

            $tool   = new BulkCreateProductsTool();
            $result = $tool->execute([
                'items'        => $items,
                'default_type' => $defaultType,
            ]);

            return response()->json([
                'transcript' => 'Import CSV ('.count($items).' lignes)',
                'action'     => 'bulk_create_products',
                'tool_result'=> $result,
                'reply'      => $result['message'] ?? 'Import terminé.',
            ]);
        } catch (\Throwable $e) {
            Log::error('[AI] /import-csv failed', ['error' => $e->getMessage()]);
            return response()->json([
                'error'  => "Échec de l'import CSV.",
                'detail' => app()->isLocal() ? $e->getMessage() : null,
            ], 500);
        }
    }
}
