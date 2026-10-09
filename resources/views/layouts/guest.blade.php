<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#c62a1d">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#131010">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>{{ isset($title) ? $title.' | '.config('app.name') : config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased">
    <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-5 py-10">
        <div class="mb-8 flex flex-col items-center gap-3 text-center">
            <x-logo class="size-16" />
            <h1 class="font-display text-4xl font-bold leading-[0.95]">Taquería<br>San Isidro</h1>
        </div>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
