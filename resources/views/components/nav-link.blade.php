@props(['route', 'icon', 'count' => 0])
@php $active = request()->routeIs($route); @endphp
<a href="{{ route($route) }}" wire:navigate.hover
   @class([
       'flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold',
       'bg-chile text-white shadow-sm' => $active,
       'text-muted hover:bg-sunken hover:text-ink' => ! $active,
   ])
   @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" /> <span class="flex-1">{{ $slot }}</span>
    @if ($count > 0)
        <span class="num grid h-6 min-w-6 place-items-center rounded-full bg-limon px-1.5 text-sm font-bold text-on-limon">{{ $count }}</span>
    @endif
</a>
