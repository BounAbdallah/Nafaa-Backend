<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\AddStockMovementTool;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\BulkCreateBomsTool;
use App\Services\Ai\Tools\BulkCreateProductsTool;
use App\Services\Ai\Tools\CreateExpenseTool;
use App\Services\Ai\Tools\CreateProductTool;
use App\Services\Ai\Tools\LaunchProductionTool;
use App\Services\Ai\Tools\ListBomsTool;
use App\Services\Ai\Tools\ListCustomersTool;
use App\Services\Ai\Tools\CreateCustomerTool;
use App\Services\Ai\Tools\ListOrdersTool;
use App\Services\Ai\Tools\QueryOrderTool;
use App\Services\Ai\Tools\ListExpensesTool;
use App\Services\Ai\Tools\BulkCreateExpensesTool;
use App\Services\Ai\Tools\ListLowStockTool;
use App\Services\Ai\Tools\ListMaterialsTool;
use App\Services\Ai\Tools\ListProductsTool;
use App\Services\Ai\Tools\QueryBomTool;
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
        // Stock & catalogue
        $this->register(new AddStockMovementTool());
        $this->register(new QueryStockTool());
        $this->register(new ListLowStockTool());
        $this->register(new ListProductsTool());
        $this->register(new CreateProductTool());
        $this->register(new BulkCreateProductsTool());

        // Production / Recettes (BOM)
        $this->register(new ListMaterialsTool());
        $this->register(new BulkCreateBomsTool());
        $this->register(new ListBomsTool());
        $this->register(new QueryBomTool());
        $this->register(new LaunchProductionTool());

        // Finance & Dépenses
        $this->register(new CreateExpenseTool());
        $this->register(new ListExpensesTool());
        $this->register(new BulkCreateExpensesTool());

        // Commerce & CRM
        $this->register(new ListOrdersTool());
        $this->register(new QueryOrderTool());
        $this->register(new ListCustomersTool());
        $this->register(new CreateCustomerTool());
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
