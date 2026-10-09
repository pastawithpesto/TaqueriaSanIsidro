<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ingredient_id', 'category', 'description', 'supplier', 'quantity', 'total', 'from_cash', 'spent_on'])]
class Expense extends Model
{
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'quantity' => 'decimal:4',
            'total' => 'decimal:2',
            'from_cash' => 'boolean',
            'spent_on' => 'date',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
