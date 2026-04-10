<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen flex flex-col">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Pinoke keuken orders') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @fluxAppearance()
</head>


<body class="antialiased flex-1 flex flex-col min-h-screen">
    <div class="flex-1 flex flex-col">
        {{ $slot }}
    </div>
    @livewireScripts
    @fluxScripts
</body>

<flux:footer class="text-center mt-auto">
    <flux:text size="xl" variant="subtle">Powered by DJMRY &amp; LarsFixt</flux:text>
</flux:footer>

</html>
