<x-app-layout>
    <x-slot name="header">
        {{ __('Planning') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">

        {{-- Board switcher --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($patternBoards as $board)
                        <a href="{{ route('planning.table', $board) }}"
                           class="px-4 py-2 rounded-lg text-sm font-semibold border transition
                                  {{ $board->id === $patternBoard->id
                                        ? 'bg-brand-800 text-white border-brand-800 shadow-sm'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300 hover:text-brand-700' }}">
                            {{ $board->name }}
                        </a>
                    @endforeach
                </div>

                <a href="{{ route('andon.planning', $patternBoard) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10H5a2 2 0 01-2-2V9a2 2 0 012-2h4m0 10h6m-6-10h6m0 0h4a2 2 0 012 2v6a2 2 0 01-2 2h-4m0-10v10"/>
                    </svg>
                    {{ __('Lihat Timeline') }}
                </a>
            </div>
        </div>

        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <h3 class="text-lg font-semibold text-gray-800">{{ $patternBoard->name }}</h3>

                <div class="flex flex-wrap items-center gap-4">
                    {{-- Date navigator --}}
                    <div class="flex items-center gap-1">
                        <button type="button" id="planning-date-prev" title="{{ __('Hari sebelumnya') }}"
                                class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <input type="date" id="planning-date" value="{{ $date }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                        <button type="button" id="planning-date-next" title="{{ __('Hari berikutnya') }}"
                                class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                        <button type="button" id="planning-date-today"
                                class="ml-1 px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold text-gray-600 hover:bg-gray-50 transition">
                            {{ __('Hari Ini') }}
                        </button>
                    </div>

                    <div class="flex items-center gap-2 text-sm text-gray-600">
                        <span>{{ __('Tampilkan') }}</span>
                        <select id="planning-per-page"
                                class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                            @foreach ([10, 25, 50, 100] as $option)
                                <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span>{{ __('entri') }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="text" id="planning-search" value="{{ $search }}" autocomplete="off"
                               placeholder="{{ __('Cari machine / part...') }}"
                               class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-56">
                        <button type="button" id="planning-reset"
                                class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">
                            {{ __('Reset') }}
                        </button>
                    </div>
                </div>
            </div>

            <div id="planning-results" class="transition-opacity duration-150">
                @include('planning._results')
            </div>
        </div>
    </div>

    <script>
        (function () {
            const boardId = @json($patternBoard->id);
            const baseUrl = @json(route('planning.table', $patternBoard));
            const actualUrlTemplate = @json(route('andon.planning.actual.update', ['pattern' => '__PATTERN__']));

            const dateInput = document.getElementById('planning-date');
            const prevButton = document.getElementById('planning-date-prev');
            const nextButton = document.getElementById('planning-date-next');
            const todayButton = document.getElementById('planning-date-today');
            const searchInput = document.getElementById('planning-search');
            const resetButton = document.getElementById('planning-reset');
            const perPageSelect = document.getElementById('planning-per-page');
            const results = document.getElementById('planning-results');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

            let debounceTimer = null;
            let controller = null;

            function fetchResults() {
                if (controller) controller.abort();
                controller = new AbortController();

                const target = new URL(baseUrl);
                if (searchInput.value.trim()) target.searchParams.set('search', searchInput.value.trim());
                target.searchParams.set('per_page', perPageSelect.value);
                target.searchParams.set('date', dateInput.value);

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
                        resetButton.classList.toggle('hidden', searchInput.value.trim() === '');
                    })
                    .catch((error) => {
                        if (error.name !== 'AbortError') results.classList.remove('opacity-50');
                    });
            }

            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(fetchResults, 350);
            });

            resetButton.addEventListener('click', function () {
                searchInput.value = '';
                searchInput.focus();
                fetchResults();
            });

            perPageSelect.addEventListener('change', fetchResults);

            function shiftDate(days) {
                const d = new Date(dateInput.value + 'T00:00:00');
                d.setDate(d.getDate() + days);
                dateInput.value = d.toISOString().slice(0, 10);
                fetchResults();
            }

            prevButton.addEventListener('click', () => shiftDate(-1));
            nextButton.addEventListener('click', () => shiftDate(1));
            todayButton.addEventListener('click', function () {
                dateInput.value = @json(now()->toDateString());
                fetchResults();
            });
            dateInput.addEventListener('change', fetchResults);

            // "Kanban override" and "actual kanban" inputs — each saves on its
            // own change event (blur/enter), for whichever date is currently
            // selected, then refreshes the table so "Kanban Efektif" and
            // pagination/search stay consistent with what was just saved.
            document.addEventListener('change', function (e) {
                const input = e.target;
                if (!input.classList) return;

                let field = null;
                if (input.classList.contains('planning-actual-input')) field = 'actual_kanban';
                if (input.classList.contains('planning-override-input')) field = 'kanban_override';
                if (!field) return;

                const patternId = input.dataset.patternId;
                const url = actualUrlTemplate.replace('__PATTERN__', patternId);

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        [field]: input.value === '' ? null : input.value,
                        produced_on: dateInput.value,
                    }),
                })
                    .then(() => fetchResults())
                    .catch(() => {
                        // Network hiccup — the value stays in the field; changing
                        // it again (even back to the same number) will retry.
                    });
            });
        })();
    </script>
</x-app-layout>
