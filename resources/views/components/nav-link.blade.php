@props(['route', 'icon', 'count' => 0])
@php $active = request()->routeIs($route); @endphp
<a href="{{ route($route) }}" wire:navigate.hover {{ $attributes }}
   @class([
       'flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-medium',
       'bg-limon text-on-limon' => $active,
       'text-panel-muted hover:bg-white/8 hover:text-on-panel' => ! $active,
   ])
   @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" /> <span class="flex-1">{{ $slot }}</span>
    @if ($count > 0)
        <span class="num grid h-6 min-w-6 place-items-center rounded-full bg-chile px-1.5 text-sm text-white">{{ $count }}</span>
    @endif
</a>
