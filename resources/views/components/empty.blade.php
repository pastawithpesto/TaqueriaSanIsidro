@props(['icon' => 'package', 'title'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center rounded-2xl border border-dashed border-line px-6 py-10 text-center']) }}>
    <span class="mb-3 grid size-12 place-items-center rounded-full bg-sunken text-muted"><x-icon :name="$icon" class="size-6" /></span>
    <p class="font-display text-xl font-bold">{{ $title }}</p>
    @if (trim((string) $slot) !== '')
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $slot }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
