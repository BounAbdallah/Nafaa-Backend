<?php

namespace App\Services\Ai\Tools;

/**
 * Contract every AI tool implements.
 * Tools are mapped 1-to-1 to OpenAI-style function calling schemas.
 */
interface AiTool
{
    /** Tool's machine-readable name (must match the schema). */
    public function name(): string;

    /**
     * JSON schema describing this tool to the LLM.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Executes the tool against the real ERP backend.
     * Receives validated arguments produced by the LLM.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>  Result returned to the LLM (and to the user).
     */
    public function execute(array $args): array;
}
