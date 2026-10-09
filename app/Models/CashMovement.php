<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'type', 'amount', 'reason', 'moved_at'])]
class CashMovement extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'moved_at' => 'datetime',
        ];
    }

    /** Monto con signo: positivo si entra a la caja, negativo si sale. */
    public function signed(): float
    {
        return $this->type === 'in' ? (float) $this->amount : -(float) $this->amount;
    }
}
