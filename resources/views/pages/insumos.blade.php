<?php

use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductExtra;
use App\Support\Names;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Insumos')] class extends Component
{
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $unit = 'kg';

    public ?string $cost_per_unit = '0';

    /** Cómo se usa en las recetas si no es como se compra: el pan se compra por paquete y se usa por pza. */
    public ?string $use_unit = null;

    /** Cuántas de esas trae lo que se compra: 8 pza por paquete. */
    public ?string $use_per_unit = null;

    /** Si cambia cómo se compra y la app no sabe la equivalencia, la pregunta: 1 paquete son ¿cuántas pza? */
    public ?string $equivalenceFrom = null;

    public ?string $equivalence = '';

    /** La unidad estaba mal puesta y las cantidades ya están en la nueva: se cambia sin convertir nada. */
    public bool $relabel = false;

    /** El insumo que se edita ya está en recetas, extras o compras. */
    public bool $inUse = false;

    /** Borrar un insumo que está en recetas o extras: se muestra qué cambia y se pide escribir BORRAR. */
    public bool $showDelete = false;

    public ?int $deleteId = null;

    public string $deleteConfirm = '';

    /** Al borrar: insumo al que pasan las compras de Gastos (opcional). */
    public ?string $moveTo = null;

    /** Reemplazar un insumo por otro: recetas, extras y compras pasan al nuevo y el original se borra. */
    public bool $showReplace = false;

    public ?int $replaceId = null;

    public ?string $replaceTo = null;

    public string $replaceConfirm = '';

    /** Vender un insumo tal cual se compra (refrescos, aguas): se crea el producto con ese insumo en su receta. */
    public bool $showSell = false;

    public ?int $sellId = null;

    /** Producto que ya vende este insumo y se está editando; null para crear otro. */
    public ?int $sellProductId = null;

    public string $sellName = '';

    public ?string $sellPrice = null;

    public ?string $sellCategory = null;

    public string $sellQuantity = '1';

    /** La búsqueda ignora mayúsculas y acentos: "limon" encuentra "Limón". Son pocos insumos, se filtra aquí. */
    #[Computed]
    public function ingredients()
    {
        $plain = fn (string $text) => Str::lower(Str::ascii($text));
        $search = $plain(trim($this->search));

        return Ingredient::withCount(['products', 'extras'])
            ->orderByDesc('active')->orderBy('name')
            ->get()
            ->when($search !== '', fn ($all) => $all->filter(fn ($i) => str_contains($plain($i->name), $search))->values());
    }

    /** "3 recetas, 2 extras y 5 compras": lo que se convierte si el insumo que se edita cambia de unidad. */
    #[Computed]
    public function impact(): string
    {
        $ingredient = $this->editingId ? Ingredient::withCount(['products', 'extras'])->find($this->editingId) : null;

        if (! $ingredient) {
            return '';
        }

        $purchases = $ingredient->expenses()->whereNotNull('quantity')->count();
        $parts = array_filter([
            $ingredient->products_count ? $ingredient->products_count.' '.($ingredient->products_count === 1 ? 'receta' : 'recetas') : null,
            $ingredient->extras_count ? $ingredient->extras_count.' '.($ingredient->extras_count === 1 ? 'extra' : 'extras') : null,
            $purchases ? $purchases.' '.($purchases === 1 ? 'compra' : 'compras') : null,
        ]);

        return match (count($parts)) {
            0 => '',
            1 => reset($parts),
            default => implode(', ', array_slice($parts, 0, -1)).' y '.end($parts),
        };
    }

    #[Computed]
    public function hasAny(): bool
    {
        return Ingredient::exists();
    }

    #[Computed]
    public function sellIngredient(): ?Ingredient
    {
        return $this->sellId ? Ingredient::find($this->sellId) : null;
    }

    /** Las de siempre más las que ya se escribieron en otros insumos ("untada"). */
    #[Computed]
    public function useUnits()
    {
        return collect(Ingredient::USE_UNITS)
            ->merge(Ingredient::whereNotNull('use_unit')->distinct()->pluck('use_unit'))
            ->unique()->values();
    }

    #[Computed]
    public function categories()
    {
        return Product::categories();
    }

    /** Productos que ya venden este insumo tal cual (con él como único ingrediente): puede haber varios sabores. */
    #[Computed]
    public function soldAs()
    {
        return $this->sellId
            ? Product::with('ingredients')->whereHas('ingredients', fn ($q) => $q->whereKey($this->sellId))->has('ingredients', '=', 1)->orderBy('name')->get()
            : collect();
    }

    /** Si ya se vende, abre ese producto para cambiar su precio; si no, uno nuevo. */
    public function startSelling(int $id): void
    {
        $this->sellId = Ingredient::findOrFail($id)->id;
        unset($this->sellIngredient, $this->soldAs);
        $this->pickSellProduct($this->soldAs->first()?->id);
        $this->showSell = true;
    }

    public function pickSellProduct(?int $productId): void
    {
        $ingredient = $this->sellIngredient;
        $product = $productId ? $this->soldAs->firstWhere('id', $productId) : null;
        $plain = fn (string $number) => rtrim(rtrim($number, '0'), '.');

        $this->resetErrorBag();
        $this->sellProductId = $product?->id;

        if ($product) {
            $this->sellName = $product->name;
            $this->sellPrice = $plain((string) $product->price);
            $this->sellCategory = $product->category;
            $this->sellQuantity = $plain((string) $product->ingredients->first()->pivot->quantity);

            return;
        }

        $this->sellName = Names::taken('products', $ingredient->name) ? '' : $ingredient->name;
        $this->sellPrice = null;
        $this->sellCategory = $this->soldAs->first()?->category;
        // Por pieza o porción lo normal es una por venta; por kilo o litro hay que decir cuánto lleva cada una.
        $this->sellQuantity = in_array($ingredient->recipeUnit(), ['kg', 'g', 'lt', 'ml'], true) ? '' : '1';
    }

    public function sell(): void
    {
        $ingredient = Ingredient::findOrFail($this->sellId);
        $editing = $this->sellProductId ? $this->soldAs->firstWhere('id', $this->sellProductId) ?? abort(404) : null;
        $this->sellName = Names::clean($this->sellName);
        $this->sellCategory = Product::normalizeCategory($this->sellCategory, $editing?->id);

        $this->validate([
            'sellName' => ['required', 'string', 'max:255', fn ($attribute, $value, $fail) => Names::taken('products', (string) $value, $editing?->id) ? $fail('Ya hay un producto con ese nombre.') : null],
            'sellPrice' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'sellCategory' => ['nullable', 'string', 'max:40'],
            'sellQuantity' => ['required', 'numeric', 'min:0.0001', 'max:99999'],
        ], [], ['sellName' => 'nombre', 'sellPrice' => 'precio', 'sellCategory' => 'categoría', 'sellQuantity' => 'cantidad']);

        $product = DB::transaction(function () use ($ingredient, $editing) {
            $fields = ['name' => $this->sellName, 'price' => $this->sellPrice, 'category' => $this->sellCategory];

            if ($editing) {
                $editing->update($fields);
                $editing->ingredients()->updateExistingPivot($ingredient->id, ['quantity' => $this->sellQuantity]);

                return $editing;
            }

            $product = Product::create($fields + ['sort_order' => (int) Product::max('sort_order') + 1]);
            $product->ingredients()->attach($ingredient->id, ['quantity' => $this->sellQuantity, 'removable' => false]);

            return $product;
        });

        $this->showSell = false;
        unset($this->ingredients, $this->categories, $this->soldAs);
        $this->dispatch('toast', message: $editing ? $product->name.' guardado' : $product->name.' ya aparece en Vender');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $this->resetForm();
        $this->editingId = $i->id;
        $this->name = $i->name;
        $this->unit = $i->unit;
        $this->cost_per_unit = rtrim(rtrim((string) $i->cost_per_unit, '0'), '.') ?: '0';
        $this->use_unit = $i->use_unit;
        $this->use_per_unit = $i->use_unit ? rtrim(rtrim((string) $i->use_per_unit, '0'), '.') : null;
        $this->inUse = $this->isInUse($i);
        unset($this->impact);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'use_unit', 'use_per_unit', 'equivalenceFrom', 'equivalence', 'relabel', 'inUse');
        unset($this->impact);
        $this->unit = 'kg';
        $this->cost_per_unit = '0';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        // Usarlo como se compra es no tener unidad de uso; de kg a g la equivalencia no se pregunta.
        $this->use_unit = Ingredient::normalizeUnit($this->use_unit, [...Ingredient::UNITS, ...$this->useUnits]);
        if ($this->use_unit === $this->unit) {
            $this->use_unit = null;
        }
        $known = $this->use_unit ? Ingredient::factorBetween($this->unit, $this->use_unit) : null;
        $this->use_per_unit = $this->use_unit ? ($known !== null ? (string) $known : $this->use_per_unit) : null;

        $this->name = Names::clean($this->name);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255', fn ($attribute, $value, $fail) => Names::taken('ingredients', (string) $value, $this->editingId) ? $fail('Ya hay un insumo con ese nombre.') : null],
            'unit' => ['required', Rule::in(Ingredient::UNITS)],
            'cost_per_unit' => ['required', 'numeric', 'min:0', 'max:999999'],
            'use_unit' => ['nullable', 'string', 'max:20', 'regex:/^[\pL ]+$/u'],
            'use_per_unit' => ['nullable', 'required_with:use_unit', 'numeric', 'min:0.0001', 'max:999999'],
        ], [
            'use_unit.regex' => 'Escribe solo la unidad, como untada o taza.',
            'use_per_unit.required_with' => 'Escribe cuántas rinde.',
        ], ['use_unit' => 'unidad', 'use_per_unit' => 'cantidad']);

        $ingredient = $this->editingId ? Ingredient::findOrFail($this->editingId) : null;

        if (! $ingredient) {
            Ingredient::create($data);
            $this->saved('Insumo guardado');

            return;
        }

        // Las recetas van en la unidad de uso y las compras en la de compra: si cambian, se convierten las cantidades.
        $old = ['unit' => $ingredient->unit, 'use' => $ingredient->recipeUnit(), 'per' => $ingredient->usesPerUnit()];
        $new = ['unit' => $data['unit'], 'use' => $data['use_unit'] ?? $data['unit'], 'per' => $data['use_unit'] ? (float) $data['use_per_unit'] : 1.0];
        $relabel = $this->relabel && $this->isInUse($ingredient);
        $purchaseFactor = $relabel ? 1.0 : $this->purchaseFactor($old, $new);
        if ($purchaseFactor === null && $this->equivalenceFrom === $old['unit']) {
            $this->validate(['equivalence' => ['required', 'numeric', 'between:0.0001,999999']], ['equivalence.required' => 'Escribe a cuánto equivale.', 'equivalence.between' => 'Escribe una cantidad entre 0.0001 y 999999.'], ['equivalence' => 'equivalencia']);
            $purchaseFactor = Ingredient::equivalenceFactor($old['unit'], $new['unit'], (float) $this->equivalence);
        }
        $recipeFactor = $relabel ? 1.0 : (Ingredient::factorBetween($old['use'], $new['use'])
            ?? ($purchaseFactor !== null ? $purchaseFactor * $new['per'] / $old['per'] : null));

        $changes = fn (?float $factor) => $factor === null || abs($factor - 1) > 0.000001;
        $convertRecipes = $changes($recipeFactor) && $this->isInRecipes($ingredient);
        $convertPurchases = $changes($purchaseFactor) && $ingredient->expenses()->whereNotNull('quantity')->exists();

        // Sin forma de saberla, se pregunta antes de guardar. Si solo se le cambia el nombre (paquete por manojo), es 1.
        if (($convertRecipes && $recipeFactor === null) || ($convertPurchases && $purchaseFactor === null)) {
            $this->equivalenceFrom = $old['unit'];
            $this->equivalence = '';

            return;
        }

        if ($error = $this->scaleError($ingredient, $convertRecipes ? $recipeFactor : null, $convertPurchases ? $purchaseFactor : null)) {
            $this->addError($this->equivalenceFrom ? 'equivalence' : 'unit', $error);

            return;
        }

        // Si no se tocó el costo, también se convierte: $6 por pza son $48 por paquete de 8.
        if ($purchaseFactor !== null && $changes($purchaseFactor) && abs((float) $data['cost_per_unit'] - (float) $ingredient->cost_per_unit) < 0.00005) {
            $data['cost_per_unit'] = round((float) $ingredient->cost_per_unit / $purchaseFactor, 4);

            if (($data['cost_per_unit'] <= 0 && (float) $ingredient->cost_per_unit > 0) || $data['cost_per_unit'] > Ingredient::MAX_QUANTITY) {
                $this->addError($this->equivalenceFrom ? 'equivalence' : 'unit', 'Con ese cambio el costo quedaría en $0 o enorme. Revisa la unidad o la equivalencia.');

                return;
            }
        }

        DB::transaction(function () use ($ingredient, $data, $convertRecipes, $recipeFactor, $convertPurchases, $purchaseFactor) {
            if ($convertRecipes) {
                $scale = ['quantity' => DB::raw('quantity * '.sprintf('%.10F', $recipeFactor))];
                DB::table('product_ingredient')->where('ingredient_id', $ingredient->id)->update($scale);
                ProductExtra::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')->update($scale);
            }
            if ($convertPurchases) {
                Expense::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')
                    ->update(['quantity' => DB::raw('quantity * '.sprintf('%.10F', $purchaseFactor))]);
            }
            $ingredient->update($data);
        });

        $moved = array_filter([
            $convertRecipes ? 'las recetas pasaron a '.Ingredient::unitLabel($new['use'], 2) : null,
            $convertPurchases ? 'las compras pasaron a '.Ingredient::unitLabel($new['unit'], 2) : null,
        ]);
        $this->saved($moved ? 'Insumo guardado: '.implode(' y ', $moved).'.' : 'Insumo guardado');
    }

    protected function saved(string $message): void
    {
        $this->resetForm();
        unset($this->ingredients, $this->hasAny, $this->useUnits);
        $this->dispatch('toast', message: $message);
    }

    /**
     * Cuántas unidades de compra nuevas es una de las anteriores, pasando por la de uso si hace falta:
     * de pza a paquete de 8 pza, una pza es 0.125 paquetes. Null si no hay forma de saberlo.
     *
     * @param  array{unit: string, use: string, per: float}  $old
     * @param  array{unit: string, use: string, per: float}  $new
     */
    protected function purchaseFactor(array $old, array $new): ?float
    {
        foreach ([[$old['unit'], 1.0], [$old['use'], $old['per']]] as [$from, $amount]) {
            foreach ([[$new['unit'], 1.0], [$new['use'], $new['per']]] as [$to, $per]) {
                if (($factor = Ingredient::factorBetween($from, $to)) !== null) {
                    return $amount * $factor / $per;
                }
            }
        }

        return null;
    }

    /**
     * Las cantidades guardadas tienen 4 decimales: si al convertirlas alguna se redondea a cero o se pasa del máximo,
     * no se convierte (se perdería la receta o la compra sin avisar).
     */
    protected function scaleError(Ingredient $ingredient, ?float $recipeFactor, ?float $purchaseFactor): ?string
    {
        $recipes = DB::table('product_ingredient')->where('ingredient_id', $ingredient->id)->where('quantity', '>', 0)->selectRaw('min(quantity) as low, max(quantity) as high')->first();
        $extras = ProductExtra::where('ingredient_id', $ingredient->id)->where('quantity', '>', 0)->selectRaw('min(quantity) as low, max(quantity) as high')->first();
        $purchases = Expense::where('ingredient_id', $ingredient->id)->where('quantity', '>', 0)->selectRaw('min(quantity) as low, max(quantity) as high')->first();

        $low = fn (...$ranges) => collect($ranges)->pluck('low')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->min();
        $high = fn (...$ranges) => collect($ranges)->pluck('high')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->max();

        if ($recipeFactor !== null && ! Ingredient::fitsAfterScaling($low($recipes, $extras), $high($recipes, $extras), $recipeFactor)) {
            return 'Con ese cambio alguna receta quedaría en 0 o con una cantidad enorme. Revisa la unidad o la equivalencia.';
        }

        if ($purchaseFactor !== null && ! Ingredient::fitsAfterScaling($low($purchases), $high($purchases), $purchaseFactor)) {
            return 'Con ese cambio alguna compra quedaría en 0 o con una cantidad enorme. Revisa la unidad o la equivalencia.';
        }

        return null;
    }

    protected function isInRecipes(Ingredient $ingredient): bool
    {
        return $ingredient->products()->exists() || ProductExtra::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')->exists();
    }

    protected function isInUse(Ingredient $ingredient): bool
    {
        return $this->isInRecipes($ingredient) || $ingredient->expenses()->whereNotNull('quantity')->exists();
    }

    public function toggle(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $i->update(['active' => ! $i->active]);
        unset($this->ingredients);
    }

    /**
     * Lo que cambia al borrar el insumo: cada receta y extra que lo usa, con su costo antes y después.
     *
     * @return array{id: int, name: string, rows: array<int, array{name: string, kind: string, uses: string, before: float, after: float, empty: bool}>, purchases: int}|null
     */
    #[Computed]
    public function deletion(): ?array
    {
        $ingredient = $this->deleteId ? Ingredient::find($this->deleteId) : null;

        if (! $ingredient) {
            return null;
        }

        $quantity = fn ($value) => rtrim(rtrim((string) $value, '0'), '.');
        $rows = [];

        foreach ($ingredient->products()->with('ingredients')->orderBy('name')->get() as $product) {
            $before = $product->unitCost();
            $rows[] = [
                'name' => $product->name,
                'kind' => 'Receta',
                'uses' => $quantity($product->pivot->quantity).' '.Ingredient::unitLabel($ingredient->recipeUnit(), (float) $product->pivot->quantity),
                'before' => $before,
                'after' => max(0.0, $before - (float) $product->pivot->quantity * $ingredient->costPerUse()),
                'empty' => $product->ingredients->count() === 1,
            ];
        }

        foreach ($ingredient->extras()->with('product')->orderBy('name')->get() as $extra) {
            $rows[] = [
                'name' => $extra->name,
                'kind' => 'Extra de '.$extra->product->name,
                'uses' => $extra->quantity ? $quantity($extra->quantity).' '.Ingredient::unitLabel($ingredient->recipeUnit(), (float) $extra->quantity) : '-',
                'before' => $extra->cost(),
                'after' => 0.0,
                'empty' => false,
            ];
        }

        $purchases = $ingredient->expenses()->count();

        return [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'rows' => $rows,
            'purchases' => $purchases,
            'targets' => $purchases ? Ingredient::where('id', '!=', $ingredient->id)->orderBy('name')->get()
                ->filter(fn (Ingredient $to) => Ingredient::factorBetween($ingredient->unit, $to->unit) !== null)
                ->mapWithKeys(fn (Ingredient $to) => [$to->id => $to->name])->all() : [],
        ];
    }

    /**
     * Si el insumo no está en nada, devuelve used = false y el navegador pide una confirmación simple;
     * si está en recetas o extras, abre el cuadro con el detalle.
     *
     * @return array{used: bool, note: string}
     */
    public function startDelete(int $id): array
    {
        $ingredient = Ingredient::withCount(['products', 'extras', 'expenses'])->findOrFail($id);
        $used = $ingredient->products_count > 0 || $ingredient->extras_count > 0;

        if ($used) {
            $this->deleteId = $ingredient->id;
            $this->deleteConfirm = '';
            $this->moveTo = null;
            $this->resetErrorBag();
            unset($this->deletion);
            $this->showDelete = true;
        }

        return ['used' => $used, 'note' => $this->purchasesNote($ingredient->expenses_count)];
    }

    /** Qué pasa con las compras de Gastos que se registraron con este insumo (al borrarlo se conservan, sin ligarse a él). */
    protected function purchasesNote(int $purchases, bool $canMove = false): string
    {
        return match (true) {
            $purchases > 0 && $canMove => ($purchases === 1 ? 'Hay 1 compra registrada' : "Hay {$purchases} compras registradas").' en Gastos con este insumo. Elige abajo a qué insumo '.($purchases === 1 ? 'pasarla' : 'pasarlas').'; si no eliges ninguno, se '.($purchases === 1 ? 'queda' : 'quedan').' en Gastos con el nombre del insumo en su descripción.',
            $purchases === 0 => 'No se ha registrado ninguna compra de este insumo en Gastos.',
            $purchases === 1 => 'Hay 1 compra registrada en Gastos con este insumo. Se conserva ahí con el nombre del insumo en su descripción, y puedes ligarla a otro insumo editándola.',
            default => "Hay {$purchases} compras registradas en Gastos con este insumo. Se conservan ahí con el nombre del insumo en su descripción, y puedes ligarlas a otro insumo editándolas.",
        };
    }

    public function cancelDelete(): void
    {
        $this->reset('showDelete', 'deleteId', 'deleteConfirm', 'moveTo');
        $this->resetErrorBag();
        unset($this->deletion);
    }

    /**
     * Insumos a los que se pueden pasar recetas, extras y compras de $from sin preguntar equivalencias:
     * misma unidad de uso y de compra, o kg con g y lt con ml.
     *
     * @return array<int, Ingredient>
     */
    protected function replacementTargets(Ingredient $from): array
    {
        return Ingredient::where('id', '!=', $from->id)->orderBy('name')->get()
            ->filter(fn (Ingredient $to) => Ingredient::factorBetween($from->recipeUnit(), $to->recipeUnit()) !== null
                && Ingredient::factorBetween($from->unit, $to->unit) !== null)
            ->keyBy('id')->all();
    }

    /**
     * Qué cambia si $this->replaceId se reemplaza por $this->replaceTo: cada receta y extra con su cantidad y costo antes y después.
     *
     * @return array{id: int, name: string, targets: array<int, string>, target: ?string, rows: array<int, array<string, mixed>>, purchases: int}|null
     */
    #[Computed]
    public function replacement(): ?array
    {
        $from = $this->replaceId ? Ingredient::find($this->replaceId) : null;

        if (! $from) {
            return null;
        }

        $targets = $this->replacementTargets($from);
        $to = $this->replaceTo ? ($targets[(int) $this->replaceTo] ?? null) : null;
        $result = [
            'id' => $from->id,
            'name' => $from->name,
            'targets' => collect($targets)->map(fn (Ingredient $t) => $t->name)->all(),
            'target' => $to?->name,
            'rows' => [],
            'purchases' => $from->expenses()->count(),
        ];

        if (! $to) {
            return $result;
        }

        $recipeFactor = Ingredient::factorBetween($from->recipeUnit(), $to->recipeUnit());
        $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        // "0.5 pza" si no cambia, "0.05 kg → 50 g" si la conversión cambia la cantidad o la unidad.
        $usage = fn (float $old, float $new) => $from->recipeUnit() === $to->recipeUnit() && abs($old - $new) < 0.00005
            ? $quantity($old).' '.Ingredient::unitLabel($from->recipeUnit(), $old)
            : $quantity($old).' '.Ingredient::unitLabel($from->recipeUnit(), $old).' → '.$quantity($new).' '.Ingredient::unitLabel($to->recipeUnit(), $new);

        foreach ($from->products()->with('ingredients')->orderBy('name')->get() as $product) {
            $old = (float) $product->pivot->quantity;
            $new = round($old * $recipeFactor, 4);
            $before = $product->unitCost();
            $result['rows'][] = [
                'name' => $product->name,
                'kind' => 'Receta',
                'uses' => $usage($old, $new),
                'before' => $before,
                'after' => max(0.0, $before - $old * $from->costPerUse() + $new * $to->costPerUse()),
            ];
        }

        foreach ($from->extras()->with('product')->orderBy('name')->get() as $extra) {
            $old = (float) $extra->quantity;
            $new = round($old * $recipeFactor, 4);
            $result['rows'][] = [
                'name' => $extra->name,
                'kind' => 'Extra de '.$extra->product->name,
                'uses' => $extra->quantity ? $usage($old, $new) : '-',
                'before' => $extra->cost(),
                'after' => $new * $to->costPerUse(),
            ];
        }

        return $result;
    }

    public function startReplace(int $id): void
    {
        $this->replaceId = Ingredient::findOrFail($id)->id;
        $this->replaceTo = null;
        $this->replaceConfirm = '';
        $this->resetErrorBag();
        unset($this->replacement);
        $this->showReplace = true;
    }

    public function cancelReplace(): void
    {
        $this->reset('showReplace', 'replaceId', 'replaceTo', 'replaceConfirm');
        $this->resetErrorBag();
        unset($this->replacement);
    }

    /** Pasa recetas, extras y compras al insumo elegido (convirtiendo cantidades) y borra el original. */
    public function replace(int $id, string $confirmation = ''): void
    {
        $from = Ingredient::withCount(['products', 'extras', 'expenses'])->findOrFail($id);
        $to = $this->replaceTo && $this->replaceId === $from->id ? ($this->replacementTargets($from)[(int) $this->replaceTo] ?? null) : null;

        if (! $to) {
            $this->addError('replaceTo', 'Elige el insumo que lo reemplaza.');

            return;
        }

        if (Str::lower(trim($confirmation)) !== 'reemplazar') {
            $this->addError('replaceConfirm', 'Escribe REEMPLAZAR para confirmar.');

            return;
        }

        $recipeFactor = Ingredient::factorBetween($from->recipeUnit(), $to->recipeUnit());
        $purchaseFactor = Ingredient::factorBetween($from->unit, $to->unit);

        if ($error = $this->scaleError($from, $recipeFactor, $purchaseFactor)) {
            $this->addError('replaceTo', $error);

            return;
        }

        DB::transaction(function () use ($from, $to, $recipeFactor, $purchaseFactor) {
            foreach (DB::table('product_ingredient')->where('ingredient_id', $from->id)->get() as $row) {
                $quantity = round((float) $row->quantity * $recipeFactor, 4);
                $existing = DB::table('product_ingredient')->where('product_id', $row->product_id)->where('ingredient_id', $to->id)->first();

                if ($existing) {
                    DB::table('product_ingredient')->where('id', $existing->id)->update(['quantity' => round((float) $existing->quantity + $quantity, 4)]);
                    DB::table('product_ingredient')->where('id', $row->id)->delete();
                } else {
                    DB::table('product_ingredient')->where('id', $row->id)->update(['ingredient_id' => $to->id, 'quantity' => $quantity]);
                }
            }

            ProductExtra::where('ingredient_id', $from->id)->get()->each(fn (ProductExtra $extra) => $extra->update([
                'ingredient_id' => $to->id,
                'quantity' => $extra->quantity === null ? null : round((float) $extra->quantity * $recipeFactor, 4),
            ]));

            Expense::where('ingredient_id', $from->id)->get()->each(fn (Expense $expense) => $expense->update([
                'ingredient_id' => $to->id,
                'quantity' => $expense->quantity === null ? null : round((float) $expense->quantity * $purchaseFactor, 4),
            ]));

            $from->delete();
            $to->syncCostFromPurchases();
        });

        $this->cancelReplace();
        $this->dispatch('toast', message: $from->name.' se reemplazó por '.$to->name.'. Lo que lo usaba ahora usa el nuevo.');
        unset($this->ingredients, $this->hasAny);
    }

    /** Borra el insumo; si está en recetas o extras, primero se quita de ellos y hay que haber escrito BORRAR. */
    public function delete(int $id, string $confirmation = ''): void
    {
        $ingredient = Ingredient::withCount(['products', 'extras', 'expenses'])->findOrFail($id);
        $used = $ingredient->products_count > 0 || $ingredient->extras_count > 0;

        if ($used && Str::lower(trim($confirmation)) !== 'borrar') {
            $this->addError('deleteConfirm', 'Escribe BORRAR para confirmar.');

            return;
        }

        $target = $used && $this->deleteId === $ingredient->id && $this->moveTo ? Ingredient::find((int) $this->moveTo) : null;
        $factor = $target ? Ingredient::factorBetween($ingredient->unit, $target->unit) : null;

        if ($target && ($factor === null || $target->is($ingredient))) {
            $this->addError('moveTo', 'Ese insumo se compra en otra unidad. Elige uno que se compre igual.');

            return;
        }

        if ($target && $error = $this->scaleError($ingredient, null, $factor)) {
            $this->addError('moveTo', $error);

            return;
        }

        DB::transaction(function () use ($ingredient, $target, $factor) {
            // Con un insumo elegido, sus compras pasan a él con la cantidad en su unidad.
            if ($target) {
                Expense::where('ingredient_id', $ingredient->id)->get()->each(fn (Expense $expense) => $expense->update([
                    'ingredient_id' => $target->id,
                    'quantity' => $expense->quantity === null ? null : round((float) $expense->quantity * $factor, 4),
                ]));
                $target->syncCostFromPurchases();
            }

            // La compra deja de ligarse al insumo: su nombre queda en la descripción para saber qué se compró.
            Expense::where('ingredient_id', $ingredient->id)->get()->each(function (Expense $expense) use ($ingredient) {
                $expense->update(['description' => mb_substr(trim($ingredient->name.($expense->description ? ': '.$expense->description : '')), 0, 255)]);
            });
            DB::table('product_ingredient')->where('ingredient_id', $ingredient->id)->delete();
            ProductExtra::where('ingredient_id', $ingredient->id)->update(['ingredient_id' => null, 'quantity' => null]);
            $ingredient->delete();
        });

        $removed = array_filter([
            $ingredient->products_count ? $ingredient->products_count.' '.($ingredient->products_count === 1 ? 'receta' : 'recetas') : null,
            $ingredient->extras_count ? $ingredient->extras_count.' '.($ingredient->extras_count === 1 ? 'extra' : 'extras') : null,
        ]);

        $moved = $target && $ingredient->expenses_count ? ' Sus compras pasaron a '.$target->name.'.' : '';

        $this->cancelDelete();
        $this->dispatch('toast', message: ($removed ? 'Insumo borrado y quitado de '.implode(' y ', $removed).'.' : 'Insumo borrado.').$moved);
        unset($this->ingredients, $this->hasAny);
    }
};
?>

<div x-data="{
        sheet: $wire.$entangle('showForm'),
        sellSheet: $wire.$entangle('showSell'),
        delSheet: $wire.$entangle('showDelete'),
        repSheet: $wire.$entangle('showReplace'),
        // Costo y unidad con los que se abrió, para devolverlos si se marca Solo corregir la unidad.
        cost0: null,
        init() { this.$watch('sheet', () => this.cost0 = null) },
        scaled(cost, from, to) {
            const factor = @js(\App\Models\Ingredient::CONVERSIONS)[from]?.[to];
            return factor ? String(Math.round(Number(cost) / factor * 10000) / 10000) : cost;
        },
     }">
    <x-page-header title="Insumos" tour="insumos">
        <x-slot:actions>
            <button type="button" @click="sheet = true" class="btn-primary" data-tour="nuevo-insumo"><x-icon name="plus" class="size-4" /> Insumo</button>
        </x-slot:actions>
        @if ($this->hasAny)
            <div class="relative" data-tour="buscar">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.250ms="search" class="input !min-h-11 !pl-10" placeholder="Buscar insumo" aria-label="Buscar insumo">
            </div>
        @endif
    </x-page-header>

    <div class="space-y-4">
    @if (! $this->hasAny)
        <x-empty icon="package" title="Empieza por tus insumos">
            Son lo que compras para cocinar: carne, tortillas, cebolla, cilantro. Con su costo la app calcula cuánto ganas en cada producto.
            <x-slot:action><button type="button" @click="sheet = true" class="btn-primary">Agregar el primer insumo</button></x-slot:action>
        </x-empty>
    @elseif ($this->ingredients->isEmpty())
        <p class="py-8 text-center text-sm text-muted">Ningún insumo coincide con "{{ $search }}".</p>
    @else
        <ul class="list">
            @foreach ($this->ingredients as $i)
                <li wire:key="i-{{ $i->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4" @if ($loop->first) data-tour="insumo" @endif>
                    <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})" class="min-w-0 flex-1 text-left">
                        <span @class(['block truncate font-semibold', 'text-muted' => ! $i->active])>{{ $i->name }}</span>
                        <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                            @unless ($i->active) <span class="badge bg-sunken text-muted">Inactivo</span> @endunless
                            {{ $i->usageLabel() }}
                        </span>
                        @if ($useCost = $i->useCostLabel())
                            <span class="mt-0.5 block text-sm text-muted">{{ $useCost }}</span>
                        @endif
                    </button>
                    <span class="text-right">
                        <span class="num block text-[1.375rem] leading-tight font-bold">{{ money($i->cost_per_unit) }}</span>
                        <span class="block text-xs text-muted">por {{ $i->unit }}</span>
                    </span>
                    <div x-data="{ menu: false }" class="relative">
                        <button type="button" x-ref="trigger" @click="menu = !menu" @click.outside="menu = false" @keydown.escape.window="menu = false" class="btn-quiet size-10 justify-center !px-0" aria-label="Opciones de {{ $i->name }}"><x-icon name="more" /></button>
                        <div x-show="menu" x-cloak x-anchor.bottom-end.offset.6.fixed="$refs.trigger" x-transition.origin.top.right class="menu" @click="menu = false">
                            <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})"><x-icon name="pencil" class="size-4 text-muted" /> Editar</button>
                            @if ($i->active)
                                <button type="button" @click="sellSheet = true; $wire.startSelling({{ $i->id }})"><x-icon name="sale" class="size-4 text-muted" /> Vender como producto</button>
                            @endif
                            <button type="button" wire:click="toggle({{ $i->id }})"><x-icon :name="$i->active ? 'eye-off' : 'eye'" class="size-4 text-muted" /> {{ $i->active ? 'Desactivar' : 'Activar' }}</button>
                            <button type="button" @click="repSheet = true; $wire.startReplace({{ $i->id }})"><x-icon name="copy" class="size-4 text-muted" /> Reemplazar por otro</button>
                            <button type="button" class="text-chile" @click="const r = await $wire.startDelete({{ $i->id }}); if (! r.used && await $store.confirm.ask({ title: @js('¿Borrar '.$i->name.'?'), message: r.note + ' Borrar el insumo no se puede deshacer.', action: 'Borrar' })) $wire.delete({{ $i->id }})"><x-icon name="trash" class="size-4" /> Borrar</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="flex justify-center gap-2 px-4 text-center text-sm text-muted"><x-icon name="info" class="mt-0.5 size-4 shrink-0" /> El costo se actualiza solo al registrar una compra en Gastos.</p>
    @endif
    </div>

        <x-sheet :title="$editingId ? 'Editar insumo' : 'Nuevo insumo'" open="sheet" dismiss="sheet = false; $wire.cancel()">
            <div wire:loading.block wire:target="edit" class="space-y-4" aria-hidden="true">
                <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
                <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            </div>
            <form wire:submit="save" id="ingredient-form" class="space-y-4" wire:loading.remove wire:target="edit">
                <div>
                    <label class="label" for="name">Nombre</label>
                    <input id="name" type="text" wire:model="name" class="input" placeholder="Bistec, tortilla, cilantro">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="unit">Se compra por</label>
                        {{-- Al editar, pasar de kg a g (o de lt a ml) también convierte el costo a la vista: $35 por kg son $0.035 por g. --}}
                        <select id="unit" wire:model="unit" class="input"
                                x-data="{ from: '' }" @focus="from = $el.value"
                                @change="
                                    const factor = @js(\App\Models\Ingredient::CONVERSIONS)[from]?.[$el.value];
                                    if ($wire.editingId && factor && ! cost0) cost0 = { unit: from, cost: $wire.cost_per_unit };
                                    if ($wire.editingId && factor && ! $wire.relabel && Number($wire.cost_per_unit) > 0) $wire.cost_per_unit = scaled($wire.cost_per_unit, from, $el.value);
                                    from = $el.value;
                                ">
                            @foreach (\App\Models\Ingredient::UNITS as $u)
                                <option value="{{ $u }}">{{ $u }}</option>
                            @endforeach
                        </select>
                        @error('unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="cost" x-text="'Costo por ' + $wire.unit">Costo por {{ $unit }}</label>
                        <x-money-input id="cost" step="any" wire:model="cost_per_unit" />
                        @error('cost_per_unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($equivalenceFrom)
                    {{-- Siempre se pregunta con la unidad grande primero: "1 paquete son 8 pza", sin importar hacia dónde se cambie. --}}
                    <div x-show="$wire.unit !== @js($equivalenceFrom)" class="space-y-2 rounded-xl bg-limon-soft px-4 py-3"
                         x-data="{
                            from: @js($equivalenceFrom),
                            get oldBigger() { return ! ['g', 'ml', 'pza'].includes(this.from) && ['g', 'ml', 'pza'].includes($wire.unit) },
                            get big() { return this.oldBigger ? this.from : $wire.unit },
                            get little() { return this.oldBigger ? $wire.unit : this.from },
                         }">
                        <p class="text-sm">Este insumo ya está en {{ $this->impact ?: 'recetas o compras' }}, medido por {{ $equivalenceFrom }}. Escribe a cuánto equivale para convertirlo:</p>
                        <div class="flex items-center gap-2">
                            <span class="text-[15px] font-semibold" x-text="'1 ' + big + ' son'"></span>
                            <input type="number" inputmode="decimal" step="any" min="0" wire:model="equivalence" class="input !min-h-11 w-24" aria-label="Equivalencia" placeholder="8">
                            <span class="text-[15px] font-semibold" x-text="unitLabel(little, Number($wire.equivalence) || 1)"></span>
                        </div>
                        @error('equivalence') <p class="error">{{ $message }}</p> @enderror
                        <p class="hint">Ejemplo: 1 paquete son 8 pza. ¿Solo cambiaste el nombre de la unidad? Toca <button type="button" class="font-semibold underline" @click="$wire.equivalence = '1'">son lo mismo</button>.</p>
                    </div>
                @endif
                <p class="hint">Si no sabes el costo exacto, pon uno aproximado. Se actualiza solo cuando registres una compra.</p>

                {{-- Lo que no se usa como se compra: la receta dice "1 pza" de pan aunque se compre por paquete. --}}
                <section class="space-y-3 rounded-2xl bg-sunken/60 p-4"
                         x-data="{
                            conversions: @js(\App\Models\Ingredient::CONVERSIONS),
                            get use() { return String($wire.use_unit ?? '').trim().toLowerCase() },
                            get same() { return ! this.use || this.use === $wire.unit },
                            get known() { return this.same ? null : (this.conversions[$wire.unit]?.[this.use] ?? null) },
                            get per() { return this.known ?? Number($wire.use_per_unit) },
                            get perLabel() {
                                const verb = ['g', 'kg'].includes(this.use) ? ' pesa' : (['ml', 'lt'].includes(this.use) ? ' trae' : ' rinde');
                                return 'Cada ' + $wire.unit + verb;
                            },
                            money(n) {
                                const whole = Math.abs(n % 1) < 0.005;
                                return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
                            },
                            get costText() {
                                const cost = Number($wire.cost_per_unit);
                                if (this.same || ! (this.per > 0) || ! (cost > 0)) return '';
                                const each = cost / this.per;
                                // En gramos se lee mejor por cada 100, salvo que lo comprado pese menos que eso.
                                return ['g', 'ml'].includes(this.use) && this.per >= 100
                                    ? '100 ' + this.use + ' te cuestan ' + this.money(each * 100) + '.'
                                    : 'Cada ' + this.use + ' te cuesta ' + this.money(each) + '.';
                            },
                         }">
                    <div>
                        <h3 class="section-title">En las recetas</h3>
                        <p class="text-sm text-muted">Llénalo si usas el insumo en otra medida que como lo compras. Por ejemplo, el pan se compra por paquete y se usa por pieza; la mayonesa, por bote y por cucharada. Si la unidad no está en la lista, escríbela.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div :class="{ 'col-span-2': same }">
                            <label class="label" for="use-unit">Se usa por</label>
                            <x-combobox id="use-unit" bind="$wire.use_unit" :options="$this->useUnits->all()" none="Como se compra" create="Nueva unidad" maxlength="20" />
                            @error('use_unit') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="! same && known === null" x-cloak>
                            <label class="label" for="use-per-unit" x-text="perLabel"></label>
                            <div class="relative">
                                <input id="use-per-unit" type="number" inputmode="decimal" step="any" min="0" wire:model="use_per_unit" class="input pr-24">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted" x-text="unitLabel(use, 2)"></span>
                            </div>
                            @error('use_per_unit') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <p x-show="known !== null" x-cloak class="self-end pb-3 text-[15px]" x-text="'1 ' + $wire.unit + ' son ' + known + ' ' + use + '.'"></p>
                    </div>
                    <p class="font-semibold" x-show="costText" x-cloak x-text="costText"></p>
                    <p class="hint" x-show="! same && known === null" x-cloak x-text="'Escribe cuánto rinde 1 ' + $wire.unit + ' completo, no lo que lleva cada producto (eso va en su receta). Si no lo sabes, pon un cálculo aproximado y ajústalo después.'"></p>
                </section>
                @if ($inUse)
                    <p class="hint">Ya está en {{ $this->impact ?: 'recetas o compras' }}. Si cambias la unidad, las cantidades se convierten solas.</p>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line px-4 py-3">
                        <input type="checkbox" wire:model="relabel" class="switch mt-0.5"
                               @change="if ($el.checked) { if (cost0) $wire.cost_per_unit = cost0.cost } else if (cost0) { $wire.cost_per_unit = scaled(cost0.cost, cost0.unit, $wire.unit) }">
                        <span>
                            <span class="block font-semibold">Solo corregir la unidad</span>
                            <span class="block text-sm text-muted">Cambia el nombre de la unidad y deja las cantidades como están. Úsalo si te equivocaste al capturarla.</span>
                        </span>
                    </label>
                @endif
            </form>
            <x-slot:footer>
                <button type="submit" form="ingredient-form" wire:loading.attr="disabled" wire:target="save,edit" class="btn-primary btn-lg w-full">Guardar insumo</button>
            </x-slot:footer>
        </x-sheet>

        {{-- Borrar un insumo que está en recetas o extras: qué cambia en cada uno y confirmación escrita. --}}
        <x-sheet title="Borrar insumo" open="delSheet" dismiss="delSheet = false; $wire.cancelDelete()">
            @if ($deletion = $this->deletion)
                <div class="space-y-4">
                    <p class="text-[15px]">Se quitará de estas recetas y extras. Su costo ya no contará ahí:</p>
                    <div class="overflow-x-auto rounded-xl border border-line">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-sunken text-xs text-muted">
                                <tr>
                                    <th scope="col" class="px-3 py-2 font-semibold">Dónde</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold">Usa</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold">Costo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($deletion['rows'] as $row)
                                    <tr wire:key="del-{{ $loop->index }}">
                                        <td class="px-3 py-2">
                                            <span class="block font-semibold">{{ $row['name'] }}</span>
                                            <span class="block text-xs text-muted">{{ $row['kind'] }}</span>
                                            @if ($row['empty'])
                                                <span class="badge mt-1 bg-limon-soft text-ink">Se queda sin receta</span>
                                            @endif
                                        </td>
                                        <td class="num px-3 py-2 text-right whitespace-nowrap">{{ $row['uses'] }}</td>
                                        <td class="num px-3 py-2 text-right whitespace-nowrap">{{ money($row['before']) }} <span class="text-muted">→</span> <span class="font-semibold">{{ money($row['after']) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="hint">{{ $this->purchasesNote($deletion['purchases'], (bool) $deletion['targets']) }} Borrar el insumo no se puede deshacer.</p>
                    @if ($deletion['targets'])
                        <div>
                            <label class="label" for="move-to">Pasar sus compras a (opcional)</label>
                            <select id="move-to" wire:model="moveTo" class="input">
                                <option value="">No pasarlas: se quedan sin insumo</option>
                                @foreach ($deletion['targets'] as $targetId => $targetName)
                                    <option value="{{ $targetId }}">{{ $targetName }}</option>
                                @endforeach
                            </select>
                            @error('moveTo') <p class="error">{{ $message }}</p> @enderror
                            <p class="hint">Solo aparecen insumos que se compran en una unidad compatible (la misma, o kg y g, lt y ml).</p>
                        </div>
                    @endif
                    <div>
                        <label class="label" for="delete-confirm">Escribe BORRAR para confirmar</label>
                        <input id="delete-confirm" type="text" wire:model="deleteConfirm" autocomplete="off" autocapitalize="characters" class="input" placeholder="BORRAR">
                        @error('deleteConfirm') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
            <x-slot:footer>
                @if ($deleteId)
                    <button type="button" class="btn-primary btn-lg w-full disabled:opacity-40"
                            :disabled="($wire.deleteConfirm ?? '').trim().toLowerCase() !== 'borrar'"
                            @click="$wire.delete({{ $deleteId }}, $wire.deleteConfirm)">Quitar y borrar</button>
                @endif
            </x-slot:footer>
        </x-sheet>

        {{-- Reemplazar un insumo por otro: todo lo que lo usa pasa al nuevo y el original se borra. --}}
        <x-sheet title="Reemplazar insumo" open="repSheet" dismiss="repSheet = false; $wire.cancelReplace()">
            @if ($replacement = $this->replacement)
                <div class="space-y-4">
                    <p class="text-[15px]">Las recetas, extras y compras de <span class="font-semibold">{{ $replacement['name'] }}</span> pasan al insumo que elijas, y {{ $replacement['name'] }} se borra.</p>
                    <div>
                        <label class="label" for="replace-to">Reemplazar por</label>
                        <select id="replace-to" wire:model.live="replaceTo" class="input">
                            <option value="">Elige un insumo</option>
                            @foreach ($replacement['targets'] as $targetId => $targetName)
                                <option value="{{ $targetId }}">{{ $targetName }}</option>
                            @endforeach
                        </select>
                        @error('replaceTo') <p class="error">{{ $message }}</p> @enderror
                        <p class="hint">Solo aparecen insumos con unidades compatibles (las mismas, o kg y g, lt y ml). Las cantidades se convierten solas.</p>
                    </div>
                    @if ($replacement['target'])
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-sunken text-xs text-muted">
                                    <tr>
                                        <th scope="col" class="px-3 py-2 font-semibold">Dónde</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">Usa</th>
                                        <th scope="col" class="px-3 py-2 text-right font-semibold">Costo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @forelse ($replacement['rows'] as $row)
                                        <tr wire:key="rep-{{ $loop->index }}">
                                            <td class="px-3 py-2">
                                                <span class="block font-semibold">{{ $row['name'] }}</span>
                                                <span class="block text-xs text-muted">{{ $row['kind'] }}</span>
                                            </td>
                                            <td class="num px-3 py-2 text-right text-xs">{{ $row['uses'] }}</td>
                                            <td class="num px-3 py-2 text-right whitespace-nowrap">{{ money($row['before']) }} <span class="text-muted">→</span> <span class="font-semibold">{{ money($row['after']) }}</span></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="px-3 py-3 text-muted">No está en ninguna receta ni extra.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <p class="hint">
                            @if ($replacement['purchases'] > 0)
                                Sus {{ $replacement['purchases'] }} {{ $replacement['purchases'] === 1 ? 'compra' : 'compras' }} de Gastos también pasan a {{ $replacement['target'] }}. El costo de cada producto se calcula con el costo de {{ $replacement['target'] }}.
                            @else
                                No tiene compras en Gastos. El costo de cada producto se calcula con el costo de {{ $replacement['target'] }}.
                            @endif
                            Esto no se puede deshacer.
                        </p>
                        <div>
                            <label class="label" for="replace-confirm">Escribe REEMPLAZAR para confirmar</label>
                            <input id="replace-confirm" type="text" wire:model="replaceConfirm" autocomplete="off" autocapitalize="characters" class="input" placeholder="REEMPLAZAR">
                            @error('replaceConfirm') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
            @endif
            <x-slot:footer>
                @if ($replaceId && $replaceTo)
                    <button type="button" class="btn-primary btn-lg w-full disabled:opacity-40"
                            :disabled="($wire.replaceConfirm ?? '').trim().toLowerCase() !== 'reemplazar'"
                            @click="$wire.replace({{ $replaceId }}, $wire.replaceConfirm)">Reemplazar y borrar</button>
                @endif
            </x-slot:footer>
        </x-sheet>

        {{-- Lo que se vende tal cual se compra: se vuelve un producto con este insumo en su receta. --}}
        <x-sheet title="Vender como producto" open="sellSheet">
            <div wire:loading.block wire:target="startSelling" class="space-y-4" aria-hidden="true">
                <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
                <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            </div>
            @if ($ingredient = $this->sellIngredient)
                <form wire:submit="sell" id="sell-form" class="space-y-4" wire:loading.remove wire:target="startSelling"
                      x-data="{
                        cost: {{ $ingredient->costPerUse() }},
                        money(n) {
                            const whole = Math.abs(n % 1) < 0.005;
                            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
                        },
                        get profitText() {
                            const c = this.cost * (Number($wire.sellQuantity) || 0), p = Number($wire.sellPrice);
                            if (! (p > 0) || ! (c > 0)) return '';
                            return p >= c
                                ? 'Te cuesta ' + this.money(c) + ' y ganas ' + this.money(p - c) + ' por cada uno.'
                                : 'Ojo: te cuesta ' + this.money(c) + ', más de lo que lo cobras.';
                        },
                      }">
                    @if ($this->soldAs->isEmpty())
                        <p class="text-sm text-muted">Para lo que vendes tal como lo compras, por ejemplo refrescos o aguas. Aparece en Vender y su costo sale de {{ $ingredient->name }}, así ves cuánto ganas.</p>
                    @else
                        <div>
                            <p class="label">Ya se vende como</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->soldAs as $p)
                                    <button type="button" wire:click="pickSellProduct({{ $p->id }})" wire:key="sold-{{ $p->id }}"
                                            class="chip gap-1.5" aria-pressed="{{ $sellProductId === $p->id ? 'true' : 'false' }}">
                                        {{ $p->name }} <span class="num font-normal">{{ money($p->price) }}</span>
                                    </button>
                                @endforeach
                                <button type="button" wire:click="pickSellProduct(null)" class="chip gap-1.5" aria-pressed="{{ $sellProductId === null ? 'true' : 'false' }}">
                                    <x-icon name="plus" class="size-4" /> Otro
                                </button>
                            </div>
                            <p class="hint mt-2">{{ $sellProductId ? 'Aquí cambias su precio o cuánto lleva cada venta.' : 'Otro producto con el mismo insumo, por ejemplo de otro tamaño o sabor.' }}</p>
                        </div>
                    @endif
                    <div>
                        <label class="label" for="sell-name">Nombre en Vender</label>
                        <input id="sell-name" type="text" wire:model="sellName" class="input" maxlength="255">
                        @error('sellName') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="sell-price">Precio de venta</label>
                            <x-money-input id="sell-price" wire:model="sellPrice" placeholder="0" />
                            @error('sellPrice') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="sell-quantity">Cada venta usa</label>
                            <div class="relative">
                                <input id="sell-quantity" type="number" inputmode="decimal" step="any" min="0" wire:model="sellQuantity" class="input pr-24">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted" x-text="unitLabel(@js($ingredient->recipeUnit()), Number($wire.sellQuantity) || 1)"></span>
                            </div>
                            @error('sellQuantity') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($example = ['kg' => 'Si vendes el kilo entero, cada venta usa 1; medio kilo, 0.5.', 'lt' => 'Si vendes el litro entero, cada venta usa 1; medio litro, 0.5.', 'g' => 'Un cuarto de kilo son 250 g.', 'ml' => 'Medio litro son 500 ml.'][$ingredient->recipeUnit()] ?? null)
                        <p class="hint -mt-2">{{ $example }}</p>
                    @endif
                    <div>
                        <label class="label" for="sell-category">Categoría</label>
                        <x-combobox id="sell-category" bind="$wire.sellCategory" :options="$this->categories->all()" placeholder="Ej. Bebidas" create="Crear categoría" clear="Quitar categoría" maxlength="40" />
                        @error('sellCategory') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <p class="hint" x-show="profitText" x-cloak x-text="profitText"
                       :class="profitText.startsWith('Ojo') && '!text-chile font-semibold'"></p>
                </form>
            @endif
            <x-slot:footer>
                <button type="submit" form="sell-form" wire:loading.attr="disabled" wire:target="sell,startSelling,pickSellProduct" class="btn-primary btn-lg w-full">{{ $sellProductId ? 'Guardar cambios' : 'Crear producto' }}</button>
            </x-slot:footer>
        </x-sheet>

</div>
