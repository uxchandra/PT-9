<x-app-layout>
    <x-slot name="header">
        {{ __('Lot Making') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-800">{{ __('Lot Making') }}</h3>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span>{{ __('Tampilkan') }}</span>
                        <select id="lm-per-page"
                                class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                            @foreach ([10, 15, 25, 50, 100] as $option)
                                <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span>{{ __('entri') }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="text" id="lm-search" value="{{ $search }}" autocomplete="off"
                               placeholder="{{ __('Cari row / kolom / part no...') }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-64">
                        <button type="button" id="lm-search-reset"
                                class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">
                            {{ __('Reset') }}
                        </button>
                    </div>

                    <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'lot-making-import')"
                            class="inline-flex items-center justify-center px-4 py-2.5 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition ease-in-out duration-150 shadow-sm">
                        {{ __('Import') }}
                    </button>
                    <a href="{{ route('lot-makings.create') }}"
                       class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
                        {{ __('Tambah Lot Making') }}
                    </a>
                </div>
            </div>

            @if (session('status'))
                <div class="mx-6 mt-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                    <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            <div id="lm-results" class="transition-opacity duration-150">
                @include('lot-makings._results')
            </div>
        </div>
    </div>

    @include('lot-makings._import-modal')

    <script>
        (function () {
            const baseUrl = @json(route('lot-makings.index'));
            const searchInput = document.getElementById('lm-search');
            const resetButton = document.getElementById('lm-search-reset');
            const perPageSelect = document.getElementById('lm-per-page');
            const results = document.getElementById('lm-results');

            let debounceTimer = null;
            let controller = null;

            function fetchResults(query, perPage) {
                if (controller) controller.abort();
                controller = new AbortController();

                const target = new URL(baseUrl);
                if (query) target.searchParams.set('q', query);
                target.searchParams.set('per_page', perPage);

                results.classList.add('opacity-50');

                fetch(target.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                })
                    .then((response) => response.text())
                    .then((html) => {
                        results.innerHTML = html;
                        results.classList.remove('opacity-50');
                        window.history.replaceState({}, '', target.toString());
                        resetButton.classList.toggle('hidden', query === '');
                    })
                    .catch((error) => {
                        if (error.name !== 'AbortError') results.classList.remove('opacity-50');
                    });
            }

            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const query = searchInput.value.trim();
                debounceTimer = setTimeout(function () {
                    fetchResults(query, perPageSelect.value);
                }, 350);
            });

            resetButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                fetchResults('', perPageSelect.value);
            });

            perPageSelect.addEventListener('change', function () {
                fetchResults(searchInput.value.trim(), perPageSelect.value);
            });
        })();
    </script>
</x-app-layout>
