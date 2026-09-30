<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen flex flex-col">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#18181b">
    @stack('meta')
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Pinoké Order Tracker">
    <title>{{ config('app.name', 'Pinoké Order Tracker') }}</title>

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="Pinoké Order Tracker">
    <meta property="og:title" content="{{ config('app.name', 'Pinoké Order Tracker') }}">
    @stack('og')
    <meta property="og:image" content="{{ asset('android-chrome-512x512.png') }}">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

    {{-- Twitter / X Card --}}
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ config('app.name', 'Pinoké Order Tracker') }}">
    @stack('twitter')
    <meta name="twitter:image" content="{{ asset('android-chrome-512x512.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @production
        <script defer src="https://umami.larsfixt.nl/script.js" data-website-id="057c461e-d015-4a5b-bce9-4b64757ede84"></script>
    @endproduction
    @fluxAppearance()
</head>


<body class="min-h-dvh flex flex-col">
    <div class="flex-1">
        {{ $slot }}
    </div>

    <flux:footer class="shrink-0 text-center p-2!">
        <flux:text size="xl" variant="subtle">
            Powered by

            <flux:link href="https://larsfixt.nl" class="inline-flex items-center align-middle" aria-label="LarsFixt">
                <img src="{{ asset('assets/larsfixt-logo.svg') }}" alt="LarsFixt" class="h-8 w-auto dark:hidden px-2">
                <img src="{{ asset('assets/larsfixt-logo-reversed.svg') }}" alt="LarsFixt"
                    class="hidden h-10 w-auto dark:block px-2">
            </flux:link>

            <span class="mx-1">|</span>

            <flux:link href="https://mryav.nl" class="inline-flex items-center align-middle" aria-label="MRY AV">
                <img src="{{ asset('assets/mryav_logo.png') }}" alt="MRY AV" class="h-8 w-auto">
            </flux:link>
        </flux:text>
    </flux:footer>

    @livewireScripts
    @fluxScripts
</body>

</html>
