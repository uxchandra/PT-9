<x-app-layout>
    <style>
        .stock-table-scroll {
            scrollbar-width: thin;
            scrollbar-color: #9ca3af #f3f4f6;
        }
        .stock-table-scroll::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        .stock-table-scroll::-webkit-scrollbar-track {
            background: #f3f4f6;
        }
        .stock-table-scroll::-webkit-scrollbar-thumb {
            background: #9ca3af;
            border-radius: 10px;
            border: 2px solid #f3f4f6;
        }
        .stock-table-scroll::-webkit-scrollbar-thumb:hover {
            background: #6b7280;
        }
    </style>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-800">{{ __('Stock Part All') }}</h3>
                <div class="flex items-center gap-2">
                    <input type="text" id="stock-part-search" value="{{ $search }}" autocomplete="off"
                           placeholder="{{ __('Cari part no / store / line...') }}"
                           class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-64">
                    <button type="button" id="stock-part-reset"
                            class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">
                        {{ __('Reset') }}
                    </button>
                </div>
            </div>

            <div id="stock-part-results" class="transition-opacity duration-150">
                @include('stock-part-all._results')
            </div>
        </div>
    </div>

    <script>
        (function () {
            const baseUrl = @json(route('stock-part-all.index'));
            const input = document.getElementById('stock-part-search');
            const resetButton = document.getElementById('stock-part-reset');
            const results = document.getElementById('stock-part-results');

            let debounceTimer = null;
            let controller = null;

            function fetchResults(query) {
                if (controller) {
                    controller.abort();
                }
                controller = new AbortController();

                const target = new URL(baseUrl);
                if (query) {
                    target.searchParams.set('q', query);
                }

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
                        if (error.name !== 'AbortError') {
                            results.classList.remove('opacity-50');
                        }
                    });
            }

            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                const query = input.value.trim();
                debounceTimer = setTimeout(function () {
                    fetchResults(query);
                }, 350);
            });

            resetButton.addEventListener('click', function () {
                input.value = '';
                input.focus();
                fetchResults('');
            });
        })();
    </script>
</x-app-layout>
