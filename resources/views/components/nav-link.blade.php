@props(['route', 'icon'])
@php $active = request()->routeIs($route); @endphp
<a href="{{ route($route) }}" wire:navigate.hover
   @class([
       'flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold',
       'bg-chile text-white shadow-sm' => $active,
       'text-muted hover:bg-sunken hover:text-ink' => ! $active,
   ])
   @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" /> {{ $slot }}
</a>
