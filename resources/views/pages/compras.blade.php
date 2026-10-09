<?php

use App\Models\Ingredient;
use App\Models\Product;
use App\Support\Consumption;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lista de compras')] class extends Component
{
    /** Día para el que se compra. */
    public string $date = '';

    /** Agregar 10% por si se vende más. */
    public bool $buffer = true;

    public function mount(): void
    {
        $this->date = today()->addDay()->toDateString();
    }

    protected function day(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->date)->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::tomorrow();
        }
    }

    #[Computed]
    public function forecast(): array
    {
        return Consumption::forecast($this->day());
    }

    #[Computed]
    public function hasRecipes(): bool
    {
        return Product::has('ingredients')->exists();
    }

    /** Redondeo práctico para comprar: piezas enteras, kilos de 100 en 100 gramos. */
    protected function roundUp(float $qty, string $unit): float
    {
        return match ($unit) {
            'kg', 'lt' => ceil($qty * 10) / 10,
            'g', 'ml' => ceil($qty / 10) * 10,
            default => ceil($qty),
        };
    }

    #[Computed]
    public function rows()
    {
        $usage = $this->forecast['usage'];
        $factor = $this->buffer ? 1.1 : 1.0;

        return Ingredient::whereIn('id', array_keys($usage))->get()
            ->map(function ($i) use ($usage, $factor) {
                $qty = $this->roundUp($usage[$i->id] * $factor, $i->unit);

                return ['name' => $i->name, 'unit' => $i->unit, 'qty' => $qty, 'cost' => $qty * (float) $i->cost_per_unit];
            })
            ->filter(fn ($r) => $r['qty'] > 0)
            ->sortByDesc('cost')
            ->values();
    }

    protected function qtyLabel(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    #[Computed]
    public function shareText(): string
    {
        $lines = $this->rows->map(fn ($r) => '- '.$r['name'].': '.$this->qtyLabel($r['qty']).' '.$r['unit']);

        return 'Compras para el '.$this->day()->translatedFormat('l j \d\e F').":\n".$lines->join("\n");
    }
};
?>

<div>
    <x-page-header title="Lista de compras">
        <x-date-nav />
    </x-page-header>

    <div class="mx-auto max-w-xl space-y-4">
        <div wire:loading.delay.short wire:target="date" class="w-full space-y-4">
            <x-skeleton-list :rows="5" />
        </div>

        <div wire:loading.remove.delay.short wire:target="date" class="space-y-4">
            @if (! $this->hasRecipes)
                <x-empty icon="cart" title="Faltan las recetas">
                    La lista se calcula con lo que lleva cada producto. Arma las recetas en Productos y vuelve aquí.
                    <x-slot:action><a href="{{ route('productos') }}" wire:navigate class="btn-primary">Ir a Productos</a></x-slot:action>
                </x-empty>
            @elseif ($this->rows->isEmpty())
                <x-empty icon="cart" title="Todavía no hay suficientes ventas">
                    Cuando tengas unos días de ventas, aquí verás cuánto comprar de cada insumo según lo que normalmente usas.
                </x-empty>
            @else
                <div class="flex items-start gap-3 rounded-2xl bg-limon-soft px-4 py-3 text-sm">
                    <x-icon name="info" class="mt-0.5 size-5 shrink-0" />
                    <p>Calculado con lo que se usó en {{ $this->forecast['basis'] }}, según las recetas. Revísalo con lo que ya tienes guardado.</p>
                </div>

                <div class="flex items-center justify-between px-1">
                    <h2 class="section-title">Comprar</h2>
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm font-semibold">
                        10% extra por si acaso
                        <input type="checkbox" wire:model.live="buffer" class="switch">
                    </label>
                </div>

                <ul class="list">
                    @foreach ($this->rows as $row)
                        <li class="flex items-center gap-3 px-4 py-3">
                            <span class="min-w-0 flex-1 truncate font-semibold">{{ $row['name'] }}</span>
                            <span class="text-right">
                                <span class="num block text-2xl leading-tight font-bold">{{ $this->qtyLabel($row['qty']) }} <span class="text-base font-semibold text-muted">{{ $row['unit'] }}</span></span>
                                <span class="block text-xs text-muted">aprox. {{ money(round($row['cost'])) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>

                <div class="card flex items-center justify-between !p-5">
                    <span class="font-semibold">Gasto estimado</span>
                    <span class="num text-[2.25rem] leading-none font-bold">{{ money(round($this->rows->sum('cost'))) }}</span>
                </div>

                <div wire:key="share-{{ $date }}-{{ (int) $buffer }}" x-data="{ text: @js($this->shareText) }" class="grid grid-cols-2 gap-2">
                    <button type="button" class="btn btn-lg"
                            @click="navigator.clipboard?.writeText(text).then(() => $dispatch('toast', { message: 'Lista copiada' }))">
                        <x-icon name="copy" class="size-4" /> Copiar
                    </button>
                    <button type="button" class="btn-primary btn-lg"
                            @click="navigator.share ? navigator.share({ text }).catch(() => {}) : window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank')">
                        Compartir
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
