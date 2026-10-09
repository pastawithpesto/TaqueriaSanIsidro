{{-- Campo para pesos con el signo dentro. --}}
<div class="relative">
    <span class="num pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-lg font-semibold text-muted">$</span>
    <input type="number" inputmode="decimal" step="0.01" min="0"
           {{ $attributes->merge(['class' => 'input num !pl-7.5 text-xl font-semibold placeholder:font-sans placeholder:text-base placeholder:font-normal']) }}>
</div>
