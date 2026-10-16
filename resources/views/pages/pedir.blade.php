<?php

use App\Support\Branding;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Pedir')] class extends Component
{
};
?>

<main class="mx-auto flex min-h-dvh max-w-sm flex-col items-center justify-center gap-4 px-5 text-center">
    <x-logo class="size-28 shadow-xl ring-4 ring-white/80" :res="192" />
    <h1 class="font-display text-4xl leading-none text-balance">{{ Branding::name() }}</h1>
    <p class="text-muted">Muy pronto podrás hacer tu pedido aquí.</p>
</main>
