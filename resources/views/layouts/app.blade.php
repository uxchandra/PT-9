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

        <style>
            [x-cloak] { display: none !important; }

            ::-webkit-scrollbar { width: 6px; height: 6px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 10px; }
            ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.45); }

            .sidebar-gradient {
                background: linear-gradient(180deg, #4e342e 0%, #2a1a17 100%);
            }

            .menu-item {
                position: relative;
                margin: 2px 0;
                border-radius: 10px;
                overflow: hidden;
                transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
            }
            .menu-item::before {
                content: '';
                position: absolute;
                left: 0; top: 0; bottom: 0;
                width: 3px;
                background: #fff;
                transform: scaleY(0);
                transition: transform 0.25s cubic-bezier(0.4,0,0.2,1);
            }
            .menu-item.active::before { transform: scaleY(1); }
            .menu-item.active { background: white; color: black; }
            .menu-item:not(.active):hover { background: rgba(255,255,255,0.07); }

            .menu-group-header {
                padding: 0.75rem 1rem;
                margin: 2px 0;
                cursor: pointer;
                border-radius: 10px;
                transition: background 0.25s;
            }
            .menu-group-header:hover { background: rgba(255,255,255,0.05); }
            .menu-group-header.group-has-active { background: rgba(255,255,255,0.12); }

            .submenu-item {
                position: relative;
                margin: 2px 0;
                border-radius: 8px;
                transition: all 0.2s cubic-bezier(0.4,0,0.2,1);
            }
            .submenu-item.active { background: rgba(255,255,255,0.2); font-weight: 500; color: white; }
            .submenu-item:not(.active):hover { background: rgba(255,255,255,0.07); transform: translateX(2px); }

            .chevron-icon { transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); }
            .chevron-icon.open { transform: rotate(180deg); }

            .logout-btn {
                border: 1px solid rgba(220,38,38,0.2);
                background: rgba(220,38,38,0.07);
                transition: all 0.25s;
            }
            .logout-btn:hover {
                background: rgba(220,38,38,0.15);
                border-color: rgba(220,38,38,0.35);
            }
        </style>
    </head>
    <body class="font-sans antialiased bg-gray-50">
        <div x-data="{ sidebarOpen: true }" class="h-screen flex overflow-hidden bg-gray-50">

            <!-- Mobile overlay -->
            <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
                 x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-150"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 class="fixed inset-0 z-40 bg-black/40 lg:hidden"></div>

            <!-- Sidebar -->
            <aside class="fixed inset-y-0 left-0 z-50 w-64 sidebar-gradient text-white shadow-2xl transform transition-transform duration-300 lg:static lg:translate-x-0 lg:shrink-0"
                   :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

                <!-- Logo -->
                <div class="flex items-center h-20 border-b border-white/10 px-5 gap-3">
                    <div class="w-11 h-11 bg-white/5 border border-white/30 rounded-xl flex items-center justify-center shrink-0 p-2">
                        <x-application-logo class="w-full h-full fill-current text-white" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-bold tracking-wide text-white truncate">{{ config('app.name', 'Laravel') }}</h1>
                        <p class="text-xs text-white/50 truncate">Admin Panel</p>
                    </div>
                </div>

                <!-- Nav -->
                <nav class="px-3 mt-3 space-y-0.5 overflow-y-auto" style="height: calc(100vh - 80px - 88px);">
                    @can('view dashboard')
                    <a href="{{ route('dashboard') }}"
                       class="menu-item flex items-center px-4 py-2.5 text-gray-300 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span class="font-semibold text-sm">{{ __('Dashboard') }}</span>
                    </a>
                    @endcan

                    @can('view andon')
                    @php
                        $andonMenuBoard = \App\Models\PatternBoard::where('name', 'A')->first()
                            ?? \App\Models\PatternBoard::orderBy('name')->first();
                    @endphp
                    <a href="{{ $andonMenuBoard ? route('andon.show', $andonMenuBoard) : route('andon.index') }}" target="_blank"
                       class="menu-item flex items-center justify-between px-4 py-2.5 text-gray-300">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span class="font-semibold text-sm">{{ __('Andon') }}</span>
                        </div>
                        <svg class="w-3.5 h-3.5 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                    @endcan

                    @can('view stock part all')
                    <a href="{{ route('stock-part-all.index') }}"
                       class="menu-item flex items-center px-4 py-2.5 text-gray-300 {{ request()->routeIs('stock-part-all.*') ? 'active' : '' }}">
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <span class="font-semibold text-sm">{{ __('Stock Part All') }}</span>
                    </a>
                    @endcan

                    @can('manage patterns')
                    <a href="{{ route('pattern-boards.index') }}"
                       class="menu-item flex items-center px-4 py-2.5 text-gray-300 {{ request()->routeIs(['pattern-boards.*', 'group-items.*', 'patterns.*']) ? 'active' : '' }}">
                        <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 6h16M4 10h16M4 14h10M4 18h10"/>
                        </svg>
                        <span class="font-semibold text-sm">{{ __('Pattern') }}</span>
                    </a>
                    @endcan

                    @canany(['manage machines', 'manage parts', 'manage rest'])
                    @php $masterDataActive = request()->routeIs(['machines.*', 'parts.*', 'rests.*']); @endphp
                    <div x-data="{ open: {{ $masterDataActive ? 'true' : 'false' }} }">
                        <button @click="open = !open" type="button"
                                class="menu-group-header w-full flex items-center justify-between text-gray-300 {{ $masterDataActive ? 'group-has-active' : '' }}">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 mr-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                                <span class="font-semibold text-sm">{{ __('Data Master') }}</span>
                            </div>
                            <svg class="w-4 h-4 chevron-icon shrink-0" :class="open ? 'open' : ''"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div x-show="open" x-cloak class="ml-8 mt-1 space-y-1">
                            @can('manage machines')
                            <a href="{{ route('machines.index') }}"
                               class="submenu-item flex items-center px-3 py-2.5 text-sm text-gray-300 {{ request()->routeIs('machines.*') ? 'active' : '' }}">
                                <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/>
                                </svg>
                                {{ __('Machine') }}
                            </a>
                            @endcan
                            @can('manage parts')
                            <a href="{{ route('parts.index') }}"
                               class="submenu-item flex items-center px-3 py-2.5 text-sm text-gray-300 {{ request()->routeIs('parts.*') ? 'active' : '' }}">
                                <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                                </svg>
                                {{ __('Part List') }}
                            </a>
                            @endcan
                            @can('manage rest')
                            <a href="{{ route('rests.index') }}"
                               class="submenu-item flex items-center px-3 py-2.5 text-sm text-gray-300 {{ request()->routeIs('rests.*') ? 'active' : '' }}">
                                <svg class="w-4 h-4 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                {{ __('Rest') }}
                            </a>
                            @endcan
                        </div>
                    </div>
                    @endcanany
                </nav>

                <!-- User / logout -->
                <div class="absolute bottom-0 inset-x-0 border-t border-white/10 p-4 bg-black/10">
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-3 mb-3 rounded-lg px-2 py-1.5 -mx-2 hover:bg-white/5 transition">
                        <div class="w-9 h-9 rounded-full bg-white/10 border border-white/20 flex items-center justify-center text-sm font-semibold shrink-0">
                            {{ Str::upper(Str::substr(Auth::user()->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
                            <p class="text-xs text-white/50 truncate">&commat;{{ Auth::user()->username }}</p>
                        </div>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="logout-btn w-full flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-red-100">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            {{ __('Log Out') }}
                        </button>
                    </form>
                </div>
            </aside>

            <!-- Main -->
            <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

                <!-- Header -->
                <header class="bg-white shadow-sm z-30 shrink-0">
                    <div class="flex items-center justify-between px-4 sm:px-6 py-4 gap-4">
                        <div class="flex items-center gap-4 min-w-0">
                            <button @click="sidebarOpen = !sidebarOpen"
                                    class="text-gray-500 hover:text-brand-800 transition shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                                </svg>
                            </button>
                            @isset($header)
                                <h2 class="font-semibold text-lg text-gray-800 truncate">
                                    {{ $header }}
                                </h2>
                            @endisset
                        </div>

                        <div class="hidden sm:block text-sm text-gray-500 font-medium shrink-0">
                            {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('l, d F Y') }}
                        </div>
                    </div>
                </header>

                <!-- Page Content -->
                <main class="flex-1 overflow-y-auto">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
