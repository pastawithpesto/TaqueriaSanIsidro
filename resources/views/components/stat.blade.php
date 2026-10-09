@props(['label', 'value', 'tone' => null, 'note' => null])
<div {{ $attributes->merge(['class' => 'card']) }}>
    <p class="text-sm font-medium text-muted">{{ $label }}</p>
    <p @class([
        'num mt-0.5 text-3xl font-bold leading-tight',
        'text-salsa' => $tone === 'good',
        'text-chile' => $tone === 'bad',
    ])>{{ $value }}</p>
    @if ($note)
        <p class="mt-0.5 text-xs text-muted">{{ $note }}</p>
    @endif
</div>
