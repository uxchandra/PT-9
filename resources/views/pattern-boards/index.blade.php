<x-app-layout>
    <x-slot name="header">
        {{ __('Pattern') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">

        {{-- Board switcher --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($patternBoards as $board)
                        <a href="{{ route('pattern-boards.index', ['board' => $board->id]) }}"
                           class="px-4 py-2 rounded-lg text-sm font-semibold border transition
                                  {{ $selectedBoard?->id === $board->id
                                        ? 'bg-brand-800 text-white border-brand-800 shadow-sm'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300 hover:text-brand-700' }}">
                            {{ $board->name }}
                        </a>
                    @endforeach
                    <a href="{{ route('pattern-boards.create') }}"
                       class="px-4 py-2 rounded-lg text-sm font-semibold border border-dashed border-gray-300 text-gray-500 hover:border-brand-400 hover:text-brand-700 transition">
                        + {{ __('Board') }}
                    </a>
                </div>

                @if ($selectedBoard)
                    <div class="flex items-center gap-2">
                        <a href="{{ route('pattern-boards.import.create', $selectedBoard) }}"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            {{ __('Import') }}
                        </a>
                        <a href="{{ route('andon.show', $selectedBoard) }}" target="_blank"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-800 rounded-lg font-semibold text-sm text-white hover:bg-gray-900 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            {{ __('Lihat Andon') }}
                        </a>
                        <a href="{{ route('andon.planning', $selectedBoard) }}" target="_blank"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10H5a2 2 0 01-2-2V9a2 2 0 012-2h4m0 10h6m-6-10h6m0 0h4a2 2 0 012 2v6a2 2 0 01-2 2h-4m0-10v10"/>
                            </svg>
                            {{ __('Andon Planning') }}
                        </a>
                        <a href="{{ route('pattern-boards.edit', $selectedBoard) }}" title="{{ __('Ganti Nama') }}"
                           class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-800 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        <form action="{{ route('pattern-boards.destroy', $selectedBoard) }}" method="POST" onsubmit="return confirm('{{ __('Hapus board ini beserta seluruh data terkait?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="{{ __('Hapus Board') }}"
                                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 text-red-500 hover:bg-red-50 hover:text-red-700 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (! $selectedBoard)
            <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-16 text-center text-gray-400">
                {{ __('Belum ada pattern board. Klik "+ Board" untuk membuat yang pertama.') }}
            </div>
        @else
            {{-- Search --}}
            <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" id="pattern-search" value="{{ $search }}" autocomplete="off"
                           placeholder="{{ __('Cari part no atau nama mesin...') }}"
                           class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                    <button type="button" id="pattern-search-reset"
                            class="text-sm text-gray-500 hover:text-gray-700 {{ $search === '' ? 'hidden' : '' }}">
                        {{ __('Reset') }}
                    </button>
                </div>
            </div>

            <div id="pattern-results" class="transition-opacity duration-150">
                @include('pattern-boards._results')
            </div>
        @endif
    </div>

    @if ($selectedBoard)
        @push('scripts')
            <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js"></script>
            <script>
                (function () {
                    const boardId = @json($selectedBoard->id);
                    const baseUrl = @json(route('pattern-boards.index'));
                    const token = document.querySelector('meta[name="csrf-token"]')?.content;
                    const results = document.getElementById('pattern-results');

                    // (Re)bind drag-and-drop to the Kelompok Pattern table — called
                    // on load and again after every AJAX search swap. Disabled
                    // while a search filter is active (the list is partial).
                    function initKpSortable() {
                        const tbody = document.getElementById('kp-sortable');
                        if (!tbody || !window.Sortable) return;
                        if (tbody._sortable) { tbody._sortable.destroy(); tbody._sortable = null; }
                        if (tbody.dataset.locked) return;

                        const url = tbody.dataset.reorderUrl;
                        const status = document.getElementById('kp-reorder-status');
                        const setStatus = (text, cls) => {
                            if (status) { status.textContent = text; status.className = 'ml-1 text-xs font-medium ' + cls; }
                        };
                        const renumber = () => {
                            tbody.querySelectorAll('tr[data-id]').forEach((tr, i) => {
                                const cell = tr.querySelector('.kp-urutan-cell');
                                if (cell) cell.textContent = i + 1;
                            });
                        };

                        tbody._sortable = Sortable.create(tbody, {
                            handle: '.kp-drag-handle',
                            animation: 150,
                            ghostClass: 'bg-brand-50',
                            onEnd() {
                                renumber();
                                const order = Array.from(tbody.querySelectorAll('tr[data-id]')).map((tr) => Number(tr.dataset.id));
                                setStatus('{{ __('Menyimpan urutan…') }}', 'text-gray-400');
                                fetch(url, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify({ order }),
                                })
                                    .then((r) => { if (!r.ok) throw new Error(); return r.json(); })
                                    .then(() => setStatus('{{ __('Urutan tersimpan ✓') }}', 'text-green-600'))
                                    .catch(() => setStatus('{{ __('Gagal menyimpan — muat ulang halaman') }}', 'text-red-600'));
                            },
                        });
                    }

                    // Debounced AJAX search over both tables.
                    (function () {
                        const input = document.getElementById('pattern-search');
                        const reset = document.getElementById('pattern-search-reset');
                        if (!input || !results) return;

                        let timer = null;
                        let controller = null;

                        function run(q) {
                            if (controller) controller.abort();
                            controller = new AbortController();

                            const url = new URL(baseUrl);
                            url.searchParams.set('board', boardId);
                            if (q) url.searchParams.set('q', q);

                            results.classList.add('opacity-50');
                            fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                                .then((r) => r.text())
                                .then((html) => {
                                    results.innerHTML = html;
                                    results.classList.remove('opacity-50');
                                    window.history.replaceState({}, '', url.toString());
                                    reset.classList.toggle('hidden', q === '');
                                    initKpSortable();
                                })
                                .catch((e) => { if (e.name !== 'AbortError') results.classList.remove('opacity-50'); });
                        }

                        input.addEventListener('input', () => {
                            clearTimeout(timer);
                            const q = input.value.trim();
                            timer = setTimeout(() => run(q), 350);
                        });
                        reset.addEventListener('click', () => { input.value = ''; input.focus(); run(''); });
                    })();

                    initKpSortable();
                })();
            </script>
        @endpush
    @endif
</x-app-layout>
