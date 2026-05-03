<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\AddStockMovementTool;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\ListLowStockTool;
use App\Services\Ai\Tools\ListProductsTool;
use App\Services\Ai\Tools\QueryStockTool;

/**
 * Centralised registry of every tool exposed to the LLM.
 * Add new tools here as the AI surface grows (orders, customers, etc.).
 */
class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    public function __construct()
    {
        $this->register(new AddStockMovementTool());
        $this->register(new QueryStockTool());
        $this->register(new ListLowStockTool());
        $this->register(new ListProductsTool());
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function schemas(): array
    {
        return array_values(array_map(fn (AiTool $t) => $t->schema(), $this->tools));
    }
}
