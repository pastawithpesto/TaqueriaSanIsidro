<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'closed_on', 'opening', 'expected', 'counted', 'difference', 'notes'])]
class CashClose extends Model
{
    protected function casts(): array
    {
        return [
            'closed_on' => 'date',
            'opening' => 'decimal:2',
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }
}
