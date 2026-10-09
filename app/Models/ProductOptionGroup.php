<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'name', 'required', 'sort_order'])]
class ProductOptionGroup extends Model
{
    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ProductOptionChoice::class, 'group_id')->orderBy('sort_order')->orderBy('id');
    }
}
