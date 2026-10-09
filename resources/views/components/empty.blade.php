@props(['icon' => 'package', 'title'])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center rounded-2xl border-2 border-dashed border-crema bg-surface/60 px-6 py-12 text-center']) }}>
    <span class="mb-3 grid size-14 place-items-center rounded-full bg-limon text-on-limon"><x-icon :name="$icon" class="size-6" /></span>
    <p class="section-title">{{ $title }}</p>
    @if (trim((string) $slot) !== '')
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $slot }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
