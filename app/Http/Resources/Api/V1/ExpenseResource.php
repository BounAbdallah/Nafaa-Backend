<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Expense;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'category'        => $this->category,
            'category_label'  => Expense::categories()[$this->category] ?? $this->category,
            'description'     => $this->description,
            'amount'          => $this->amount,
            'payment_method'  => $this->payment_method,
            'payment_label'   => Expense::paymentMethods()[$this->payment_method] ?? $this->payment_method,
            'expense_date'    => $this->expense_date?->toDateString(),
            'notes'           => $this->notes,
            'recorded_by'     => $this->whenLoaded('user', fn() => $this->user?->name),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
