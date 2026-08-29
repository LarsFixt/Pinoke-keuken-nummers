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
    @if (app()->isProduction())
        <script defer src="https://cloud.umami.is/script.js" data-website-id="bc315229-babf-4984-a17b-d0cf6ba4fe3e"></script>
    @endif
    @fluxAppearance()
</head>


<body class="flex-1 flex flex-col min-h-95vh">
    <div>
        {{ $slot }}
    </div>
    @livewireScripts
    @fluxScripts
</body>

<flux:footer class="text-center mt-auto">
    <flux:text size="xl" variant="subtle">Powered by DJMRY &amp; <flux:link href="https://github.com/LarsFixt">
            LarsFixt</flux:link>
    </flux:text>
</flux:footer>

</html>
