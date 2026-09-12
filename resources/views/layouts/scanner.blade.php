<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- Rugged handheld (SEUIC AutoID Q9): full device width, no pinch-zoom. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="user-authenticated" content="{{ auth()->check() ? '1' : '0' }}">
    <meta name="theme-color" content="#0f172a">
    <title>@yield('title', 'KESEI KANBAN')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html { -webkit-text-size-adjust: 100%; }
        body { overscroll-behavior: none; }
        .tap { transition: transform .06s ease, filter .06s ease; }
        .tap:active { transform: scale(.97); filter: brightness(.92); }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 font-sans antialiased select-none">
    <div class="mx-auto flex min-h-screen w-full max-w-md flex-col">

        <header class="flex items-center justify-between gap-3 bg-slate-900 px-4 py-3 text-white">
            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-widest text-slate-400">Line 9</p>
                <h1 class="truncate text-lg font-extrabold tracking-wide">@yield('heading', 'KESEI KANBAN')</h1>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                @csrf
                <button type="submit"
                        class="tap rounded-lg border border-white/20 bg-white/10 px-3 py-2 text-xs font-semibold uppercase tracking-wide">
                    {{ __('Keluar') }}
                </button>
            </form>
        </header>

        <main class="flex-1 p-4">
            @yield('content')
        </main>

        <footer class="px-4 pb-4 text-center text-[11px] text-slate-400">
            {{ auth()->user()?->name }} · {{ now()->format('d M Y H:i') }}
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
