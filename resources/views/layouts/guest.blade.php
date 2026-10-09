<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-sm flex-col justify-center px-4">
        <h1 class="mb-6 text-center text-2xl font-semibold">{{ config('app.name') }}</h1>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
