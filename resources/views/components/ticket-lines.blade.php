@props(['lines'])
@use('Illuminate\Support\Number')
<ul class="divide-y divide-line">
    @foreach ($lines as $line)
        <li wire:key="line-{{ $line['product']->id }}" class="flex items-center gap-3 py-2">
            <span class="num w-8 text-2xl font-bold text-chile">{{ $line['qty'] }}</span>
            <span class="min-w-0 flex-1 truncate text-[15px] font-medium">{{ $line['product']->name }}</span>
            <span class="num text-lg font-semibold">{{ Number::currency($line['subtotal']) }}</span>
            <button type="button" wire:click="remove({{ $line['product']->id }})" class="btn-quiet -mr-2 size-9 justify-center !px-0" aria-label="Quitar uno de {{ $line['product']->name }}">
                <x-icon name="minus" class="size-4" />
            </button>
        </li>
    @endforeach
</ul>
