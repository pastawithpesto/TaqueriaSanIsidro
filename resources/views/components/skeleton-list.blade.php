@props(['rows' => 4])
{{-- Lista de relleno mientras llegan los datos. --}}
<div {{ $attributes->merge(['class' => 'list']) }} aria-hidden="true">
    @for ($i = 0; $i < $rows; $i++)
        <div class="flex items-center gap-3 px-4 py-3.5">
            <span class="size-9 shrink-0 animate-pulse rounded-xl bg-sunken"></span>
            <span class="flex-1 space-y-2">
                <span class="block h-3.5 animate-pulse rounded bg-sunken" style="width: {{ [70, 55, 80, 45][$i % 4] }}%"></span>
                <span class="block h-3 w-1/3 animate-pulse rounded bg-sunken"></span>
            </span>
            <span class="h-6 w-16 animate-pulse rounded bg-sunken"></span>
        </div>
    @endfor
</div>
