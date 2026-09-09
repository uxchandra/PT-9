<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex bg-gray-50">

            <!-- Brand panel -->
            <div class="hidden lg:flex lg:w-[45%] xl:w-2/5 relative flex-col justify-between px-12 py-12 text-white overflow-hidden"
                 style="background: linear-gradient(160deg, rgba(78,52,46,0.65) 0%, rgba(20,13,11,0.75) 100%), url('{{ asset('images/bg-login.png') }}'); background-size: cover; background-position: center;">

                <!-- Decorative glow -->
                <div class="pointer-events-none absolute -top-24 -left-24 w-72 h-72 rounded-full bg-white/5 blur-3xl"></div>
                <div class="pointer-events-none absolute bottom-0 right-0 w-96 h-96 rounded-full bg-white/5 blur-3xl"></div>

                <div class="relative flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl border border-white/30 bg-white/5 flex items-center justify-center p-2 shrink-0">
                        <x-application-logo class="w-full h-full fill-current text-white" />
                    </div>
                    <div>
                        <p class="font-bold tracking-wide leading-tight">{{ config('app.name', 'Laravel') }}</p>
                        <p class="text-xs text-white/50">Admin Panel</p>
                    </div>
                </div>

                <p class="relative text-xs text-white/40">&copy; {{ date('Y') }} {{ config('app.name', 'Laravel') }}. All rights reserved.</p>
            </div>

            <!-- Form panel -->
            <div class="flex flex-1 flex-col justify-center items-center px-6 py-12">
                <div class="w-full max-w-sm">
                    <div class="bg-white lg:bg-transparent shadow-sm lg:shadow-none border border-gray-100 lg:border-0 rounded-2xl p-6 sm:p-8 lg:p-0">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
