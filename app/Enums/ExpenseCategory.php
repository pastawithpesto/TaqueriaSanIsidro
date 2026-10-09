<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Ingredients = 'ingredients';
    case Disposables = 'disposables';
    case Gas = 'gas';
    case Equipment = 'equipment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ingredients => 'Insumos',
            self::Disposables => 'Desechables',
            self::Gas => 'Gas',
            self::Equipment => 'Equipo',
            self::Other => 'Otros',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ingredients => 'package',
            self::Disposables => 'cup',
            self::Gas => 'flame',
            self::Equipment => 'wrench',
            self::Other => 'more',
        };
    }
}
