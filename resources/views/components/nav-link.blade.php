@props(['route', 'icon', 'pending' => false])
@php $active = request()->routeIs($route); @endphp
{{-- Sin .hover: la página que se carga al pasar el mouse se guarda 30 s y puede llegar sin el pedido recién guardado. --}}
<a href="{{ route($route) }}" wire:navigate {{ $attributes }}
   @class([
       'flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-medium',
       'bg-limon text-on-limon' => $active,
       'text-panel-muted hover:bg-white/8 hover:text-on-panel' => ! $active,
   ])
   @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" /> <span class="flex-1">{{ $slot }}</span>
    @if ($pending)
        {{-- El contador vive en $store.pending (app.js) para cambiar sin recargar la página. --}}
        <span x-data x-show="$store.pending > 0" x-cloak x-text="$store.pending" class="num grid h-6 min-w-6 place-items-center rounded-full bg-chile px-1.5 text-sm text-white"></span>
    @endif
</a>
