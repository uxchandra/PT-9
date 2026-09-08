<x-app-layout>
    <x-slot name="header">
        {{ __('Stock Snapshot') }}
    </x-slot>

    <style>
        .ss-table-scroll {
            scrollbar-width: thin;
            scrollbar-color: #9ca3af #f3f4f6;
        }
        .ss-table-scroll::-webkit-scrollbar { width: 12px; height: 12px; }
        .ss-table-scroll::-webkit-scrollbar-track { background: #f3f4f6; }
        .ss-table-scroll::-webkit-scrollbar-thumb {
            background: #9ca3af;
            border-radius: 10px;
            border: 3px solid #f3f4f6;
        }
        .ss-table-scroll::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        .ss-table-scroll::-webkit-scrollbar-corner { background: #f3f4f6; }
    </style>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">{{ __('Stock Snapshot') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Rekaman stok per 5 menit (proses TD), untuk part di Pattern & Kesei. Disimpan 7 hari.') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-1">
                        <a href="{{ route('stock-snapshots.index', ['date' => $prevDate]) }}"
                           class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </a>
                        <input type="date" id="ss-date" value="{{ $date }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                        <a href="{{ route('stock-snapshots.index', ['date' => $nextDate]) }}"
                           class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="text" id="ss-search" value="{{ $search }}" autocomplete="off"
                               placeholder="{{ __('Cari part no...') }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-52">
                        <button type="button" id="ss-search-reset"
                                class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">{{ __('Reset') }}</button>
                    </div>
                </div>
            </div>

            <div id="ss-results" class="transition-opacity duration-150">
                @include('stock-snapshots._results')
            </div>
        </div>
    </div>

    <script>
        (function () {
            const baseUrl = @json(route('stock-snapshots.index'));
            const dateInput = document.getElementById('ss-date');
            const searchInput = document.getElementById('ss-search');
            const resetButton = document.getElementById('ss-search-reset');
            const results = document.getElementById('ss-results');

            let timer = null;
            let controller = null;

            function run() {
                if (controller) controller.abort();
                controller = new AbortController();

                const url = new URL(baseUrl);
                url.searchParams.set('date', dateInput.value);
                const q = searchInput.value.trim();
                if (q) url.searchParams.set('q', q);

                results.classList.add('opacity-50');
                fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                    .then((r) => r.text())
                    .then((html) => {
                        results.innerHTML = html;
                        results.classList.remove('opacity-50');
                        window.history.replaceState({}, '', url.toString());
                        resetButton.classList.toggle('hidden', q === '');
                    })
                    .catch((e) => { if (e.name !== 'AbortError') results.classList.remove('opacity-50'); });
            }

            dateInput.addEventListener('change', run);
            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(run, 350);
            });
            resetButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                run();
            });
        })();
    </script>
</x-app-layout>
