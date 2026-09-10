{{--
    Shared shell for the Kesei history pages — same layout/UX as Stock Part All.
    Params: $pageTitle, $routeName, $resultsPartial, $searchPlaceholder, $perPage, $search
--}}
<x-app-layout>
    <x-slot name="header">{{ __('Kesei') }}</x-slot>

    <style>
        .kesei-table-scroll { scrollbar-width: thin; scrollbar-color: #9ca3af #f3f4f6; }
        .kesei-table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
        .kesei-table-scroll::-webkit-scrollbar-track { background: #f3f4f6; }
        .kesei-table-scroll::-webkit-scrollbar-thumb { background: #9ca3af; border-radius: 10px; border: 2px solid #f3f4f6; }
        .kesei-table-scroll::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    </style>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-800">{{ $pageTitle }}</h3>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span>{{ __('Tampilkan') }}</span>
                        <select id="kesei-hist-per-page"
                                class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                            @foreach ([10, 25, 50, 100] as $option)
                                <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span>{{ __('entri') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" id="kesei-hist-search" value="{{ $search }}" autocomplete="off"
                               placeholder="{{ $searchPlaceholder }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-64">
                        <button type="button" id="kesei-hist-reset"
                                class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">
                            {{ __('Reset') }}
                        </button>
                    </div>
                </div>
            </div>

            <div id="kesei-hist-results" class="transition-opacity duration-150">
                @include($resultsPartial)
            </div>
        </div>
    </div>

    <script>
        (function () {
            const baseUrl = @json(route($routeName));
            const input = document.getElementById('kesei-hist-search');
            const resetButton = document.getElementById('kesei-hist-reset');
            const perPageSelect = document.getElementById('kesei-hist-per-page');
            const results = document.getElementById('kesei-hist-results');

            let debounceTimer = null;
            let controller = null;

            function fetchResults(query, perPage) {
                if (controller) controller.abort();
                controller = new AbortController();

                const target = new URL(baseUrl);
                if (query) target.searchParams.set('q', query);
                target.searchParams.set('per_page', perPage);

                results.classList.add('opacity-50');

                fetch(target.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                    .then((r) => r.text())
                    .then((html) => {
                        results.innerHTML = html;
                        results.classList.remove('opacity-50');
                        window.history.replaceState({}, '', target.toString());
                        resetButton.classList.toggle('hidden', query === '');
                    })
                    .catch((e) => { if (e.name !== 'AbortError') results.classList.remove('opacity-50'); });
            }

            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const query = input.value.trim();
                debounceTimer = setTimeout(() => fetchResults(query, perPageSelect.value), 350);
            });
            resetButton.addEventListener('click', function () {
                input.value = '';
                input.focus();
                fetchResults('', perPageSelect.value);
            });
            perPageSelect.addEventListener('change', function () {
                fetchResults(input.value.trim(), perPageSelect.value);
            });
        })();
    </script>
</x-app-layout>
