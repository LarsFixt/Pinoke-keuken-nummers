<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#18181b">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Pinoké Order Tracker">

<title>
    {{ filled($title ?? null) ? $title . ' - ' . config('app.name', 'Pinoké Order Tracker') : config('app.name', 'Pinoké Order Tracker') }}
</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
