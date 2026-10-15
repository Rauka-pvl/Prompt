<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="@yield('html_class')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('messages.game.title'))</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased @yield('body_class')">
    <div class="@yield('shell_class', 'page-shell')">
        @hasSection('header')
            @yield('header')
        @else
            <header class="page-header">
                <a href="{{ route('home') }}" class="font-display text-xl font-extrabold tracking-tight text-gold sm:text-2xl">
                    {{ __('messages.game.title') }}
                </a>
                <nav class="flex flex-wrap items-center justify-end gap-2 text-sm text-white/60">
                    @yield('nav')
                    @include('partials.locale-switcher')
                </nav>
            </header>
        @endif

        <main class="page-main">
            @if (session('status'))
                <div class="status-banner">
                    {{ session('status') }}
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>
