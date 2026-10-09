@use('App\Support\Branding')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffd657">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#3d2e0c">
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="{{ Branding::logoUrl(64) }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ Branding::logoUrl(180) }}">
    <title>{{ isset($title) ? $title.' | '.Branding::name() : Branding::name() }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-brand min-h-dvh antialiased dark:bg-bg">
    <main class="mx-auto flex min-h-dvh max-w-sm flex-col justify-center px-5 py-10">
        <div class="mb-8 flex flex-col items-center gap-3 text-center">
            <x-logo class="size-32 shadow-xl ring-4 ring-white/80" :res="192" />
            <h1 class="font-display text-[2.5rem] leading-[0.95] text-balance">{{ Branding::name() }}</h1>
        </div>
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
