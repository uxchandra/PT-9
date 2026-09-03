<x-app-layout>
    <x-slot name="header">
        {{ __('Dashboard') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <h3 class="text-lg font-semibold text-gray-800">{{ __('Welcome back, :name', ['name' => Auth::user()->name]) }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ __("You're logged in!") }}</p>
        </div>

        @can('view andon')
            <a href="{{ route('andon.index') }}" target="_blank"
               class="group block bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden hover:shadow-md hover:border-brand-300 transition-all duration-200">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">{{ __('Andon Board') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500">{{ __('Live preview status produksi (pattern otomatis ikut Calendar) — klik untuk buka penuh') }}</p>
                    </div>
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-brand-700 shrink-0 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </div>
                <div class="relative bg-slate-100 overflow-hidden" style="height: 320px;">
                    <iframe src="{{ route('andon.index') }}"
                            class="absolute top-0 left-0 border-0 pointer-events-none"
                            style="width: 300%; height: 300%; transform: scale(0.3333); transform-origin: top left;"
                            loading="lazy" tabindex="-1" aria-hidden="true"></iframe>
                </div>
            </a>
        @endcan
    </div>
</x-app-layout>
