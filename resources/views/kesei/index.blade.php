<x-app-layout>
    <x-slot name="header">
        {{ __('Kesei') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">{{ __('Kesei') }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ __('Part yang dipantau di Andon Kesei beserta timeline stoknya.') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-2">
                    <input type="text" id="kesei-search" autocomplete="off"
                           placeholder="{{ __('Cari part no / SOS code / level...') }}"
                           class="rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700 w-64">
                    <button type="button" id="kesei-search-reset" class="hidden text-sm text-gray-500 hover:text-gray-700">
                        {{ __('Reset') }}
                    </button>
                </div>
                <a href="{{ route('kesei.import.create') }}"
                   class="inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                    {{ __('Import') }}
                </a>
                <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'kesei-create')"
                        class="inline-flex items-center justify-center px-4 py-2 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition">
                    {{ __('Tambah') }}
                </button>
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

        {{-- List --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <p class="px-6 pt-4 text-sm text-gray-500">
                {{ $keseiParts->count() }} {{ __('part') }}
                <span id="kesei-reorder-status" class="ml-1 text-xs font-medium"></span>
            </p>

            <div class="kesei-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(105vh - 380px);">
                <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-gray-300 w-8"></th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-l border-gray-300">{{ __('No') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-l border-gray-300 w-28 whitespace-nowrap">{{ __('Part No') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-l border-gray-300 w-20 whitespace-nowrap">{{ __('Level') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-l border-gray-300 w-28 whitespace-nowrap">{{ __('Perintah Pulling') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-l border-gray-300 w-24 whitespace-nowrap">{{ __('LT/KBN') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-l border-gray-300">{{ __('SOS Code') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 w-40">{{ __('Closing Time') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 w-40">{{ __('Pattern') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-3 py-3 border-b-2 border-l border-gray-300 w-12 text-center">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody id="kesei-sortable" class="divide-y divide-gray-100"
                           data-reorder-url="{{ route('kesei.reorder') }}">
                        @forelse ($keseiParts as $item)
                            <tr class="hover:bg-gray-50" data-id="{{ $item->id }}">
                                <td class="kesei-drag-handle px-3 py-3 border-b border-gray-300 text-center text-gray-300 hover:text-gray-500 cursor-grab select-none"
                                    title="{{ __('Geser untuk mengatur urutan') }}">⠿</td>
                                <td class="kesei-urutan-cell px-6 py-3 border-b border-l border-gray-300 text-gray-600">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 border-b border-l border-gray-300 whitespace-nowrap text-gray-800 font-medium">{{ $item->part?->part_no ?? '-' }}</td>
                                <td class="px-3 py-3 border-b border-l border-gray-300">
                                    <input type="text"
                                           class="kesei-inline w-16 rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="level"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->level }}"
                                           placeholder="—">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-3 py-3 border-b border-l border-gray-300">
                                    <input type="number" min="0" inputmode="numeric"
                                           class="kesei-inline w-20 rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="pulling_command"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->pulling_command }}"
                                           placeholder="—"
                                           title="{{ __('Target Finish Goods saat ini — otomatis lanjut mengikuti penurunan stok & scan setelah diisi.') }}">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-3 py-3 border-b border-l border-gray-300">
                                    <input type="number" min="0" inputmode="numeric"
                                           class="kesei-inline w-16 rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="lt_per_kbn"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->lt_per_kbn }}"
                                           placeholder="—"
                                           title="{{ __('Lead Time per Kanban (menit) — jeda antar tick di Andon Heijunka & Perintah Pulling. Kosong/0 = tidak ada jeda (langsung seperti biasa).') }}">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-6 py-3 border-b border-l border-gray-300">
                                    <input type="text"
                                           class="kesei-inline w-full max-w-xs rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="stock_source"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->stock_source }}">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-4 py-3 border-b border-l border-gray-300">
                                    <div class="flex items-center gap-1">
                                        <details class="kesei-closings relative" data-url="{{ route('kesei.update', $item) }}">
                                            <summary class="list-none cursor-pointer text-xs text-gray-700 hover:text-brand-600 [&::-webkit-details-marker]:hidden">
                                                <span class="kesei-closings-text">{{ $item->closingTimesLabel() }}</span>
                                            </summary>
                                            <div class="absolute z-20 mt-1 w-60 rounded-md border border-gray-200 bg-white shadow-lg p-2 flex flex-col gap-2">
                                                <div class="kesei-closings-rows flex flex-col gap-1.5">
                                                    @foreach ($item->closings as $closing)
                                                        <div class="kesei-closing-row flex items-center gap-1">
                                                            <input type="time" value="{{ $closing->closing_time->format('H:i') }}"
                                                                   class="kesei-closing-time flex-1 rounded-md border-gray-300 text-xs focus:border-brand-500 focus:ring-brand-500">
                                                            <label class="inline-flex items-center gap-1 text-[11px] text-gray-600" title="{{ __('Checklist = closing sebelum run. Kosong = closing di akhir produksi.') }}">
                                                                <input type="checkbox" class="kesei-closing-mode rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked($closing->isPreRunClosing())>
                                                                {{ __('Pre') }}
                                                            </label>
                                                            <button type="button" class="kesei-closing-remove text-gray-300 hover:text-red-600" aria-label="{{ __('Hapus') }}">&times;</button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <div class="flex items-center justify-between gap-1">
                                                    <button type="button" class="kesei-closing-add text-[11px] font-semibold text-brand-700 hover:text-brand-900">+ {{ __('Tambah jam') }}</button>
                                                    <button type="button" class="kesei-closing-save rounded bg-brand-700 px-2 py-1 text-[11px] font-semibold text-white hover:bg-brand-800">{{ __('Simpan') }}</button>
                                                </div>
                                            </div>
                                        </details>
                                        <span class="kesei-status text-xs"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 border-b border-l border-gray-300">
                                    <div class="flex items-center gap-1">
                                        <details class="kesei-pattern relative" data-url="{{ route('kesei.update', $item) }}">
                                            <summary class="list-none cursor-pointer text-xs text-gray-700 hover:text-brand-600 [&::-webkit-details-marker]:hidden">
                                                <span class="kesei-pattern-text">{{ $item->patternBoards->pluck('name')->implode(', ') ?: '—' }}</span>
                                            </summary>
                                            <div class="absolute z-20 mt-1 min-w-[9rem] rounded-md border border-gray-200 bg-white shadow-lg p-2 flex flex-col gap-1">
                                                @forelse ($patternBoards as $board)
                                                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-700">
                                                        <input type="checkbox" value="{{ $board->id }}"
                                                               @checked($item->patternBoards->contains('id', $board->id))
                                                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                                        {{ $board->name }}
                                                    </label>
                                                @empty
                                                    <span class="text-xs text-gray-400">{{ __('Belum ada pattern board') }}</span>
                                                @endforelse
                                            </div>
                                        </details>
                                        <span class="kesei-status text-xs"></span>
                                    </div>
                                </td>
                                <td class="px-3 py-3 border-b border-l border-gray-300 text-center">
                                    <form action="{{ route('kesei.destroy', $item) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus part ini dari Kesei?') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="inline-flex text-gray-400 hover:text-red-600 transition" title="{{ __('Hapus') }}" aria-label="{{ __('Hapus') }}">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-8 text-center text-gray-400 border-b border-gray-300">{{ __('Belum ada part di Kesei.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @include('kesei._create-modal')

    @push('styles')
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <style>
            .kesei-table-scroll { scrollbar-width: thin; scrollbar-color: #9ca3af #f3f4f6; }
            .kesei-table-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
            .kesei-table-scroll::-webkit-scrollbar-track { background: #f3f4f6; }
            .kesei-table-scroll::-webkit-scrollbar-thumb { background: #9ca3af; border-radius: 10px; border: 2px solid #f3f4f6; }
            .kesei-table-scroll::-webkit-scrollbar-thumb:hover { background: #6b7280; }
            .select2-container .select2-selection--single { height: 38px; border-color: #d1d5db; border-radius: 0.375rem; display: flex; align-items: center; }
            .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 1.5; color: #374151; padding-left: 0.75rem; }
            .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
            .select2-container--default.select2-container--focus .select2-selection--single,
            .select2-container--default.select2-container--open .select2-selection--single { border-color: #5d4037; box-shadow: 0 0 0 1px #5d4037; }
            .select2-dropdown { border-color: #d1d5db; }
            .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #5d4037; }
        </style>
    @endpush

    @push('scripts')
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js"></script>
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery('.js-select2').select2({ width: '100%', placeholder: '{{ __('Cari part no...') }}', allowClear: true });
                }

                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

                function save(url, body, badge) {
                    const set = (text, cls) => { if (badge) { badge.textContent = text; badge.className = 'kesei-status ml-1 text-xs ' + cls; } };
                    set('…', 'text-gray-400');
                    return fetch(url, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify(body),
                    })
                        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                        .then(function (d) { set('✓', 'text-green-600'); return d; })
                        .catch(function () { set('✕', 'text-red-600'); });
                }

                // Client-side filter — the whole list is already loaded (drag
                // reorder needs every row present), so search just hides
                // non-matching rows instead of round-tripping to the server.
                const searchInput = document.getElementById('kesei-search');
                const searchReset = document.getElementById('kesei-search-reset');
                const sortable = document.getElementById('kesei-sortable');
                if (searchInput && sortable) {
                    const applyFilter = function () {
                        const q = searchInput.value.trim().toLowerCase();
                        searchReset.classList.toggle('hidden', q === '');
                        sortable.querySelectorAll('tr[data-id]').forEach(function (tr) {
                            const partNo = tr.children[2]?.textContent || '';
                            const level = tr.querySelector('[data-field="level"]')?.value || '';
                            const sos = tr.querySelector('[data-field="stock_source"]')?.value || '';
                            const haystack = (partNo + ' ' + level + ' ' + sos).toLowerCase();
                            tr.classList.toggle('hidden', q !== '' && ! haystack.includes(q));
                        });
                    };
                    searchInput.addEventListener('input', applyFilter);
                    searchReset.addEventListener('click', function () {
                        searchInput.value = '';
                        searchInput.focus();
                        applyFilter();
                    });
                }

                // Inline auto-save of Source / Level.
                document.querySelectorAll('.kesei-inline').forEach(function (field) {
                    const badge = field.closest('td').querySelector('.kesei-status');
                    field.addEventListener('change', function () {
                        const value = field.type === 'checkbox'
                            ? (field.checked ? field.dataset.checkedValue : field.dataset.uncheckedValue)
                            : field.value;
                        save(field.dataset.url, { [field.dataset.field]: value }, badge)
                            .then(function (d) {
                                if (!d) return;
                                if (field.dataset.field === 'stock_source') field.value = d.stock_source ?? '';
                                if (field.dataset.field === 'level') field.value = d.level ?? '';
                                if (field.dataset.field === 'pulling_command') field.value = d.pulling_command ?? '';
                                if (field.dataset.field === 'lt_per_kbn') field.value = d.lt_per_kbn ?? '';
                            });
                    });
                });

                // Pattern: a compact "A, B, C" text that opens a checkbox
                // dropdown. Auto-saves on change and refreshes the text.
                document.querySelectorAll('.kesei-pattern').forEach(function (cell) {
                    const text = cell.querySelector('.kesei-pattern-text');
                    const badge = cell.parentElement.querySelector('.kesei-status');
                    cell.addEventListener('change', function () {
                        const checked = Array.from(cell.querySelectorAll('input[type="checkbox"]:checked'));
                        text.textContent = checked.map(function (c) { return c.parentElement.textContent.trim(); }).join(', ') || '—';
                        save(cell.dataset.url, { pattern_board_ids: checked.map(function (c) { return Number(c.value); }) }, badge);
                    });
                    // Click anywhere outside closes the dropdown.
                    document.addEventListener('click', function (e) {
                        if (cell.open && !cell.contains(e.target)) cell.open = false;
                    });
                });

                // Closing Time: a "05:00, 15:00" text that opens a dropdown of
                // add/remove rows (time + pre-run toggle). Explicit Save button —
                // a freshly-added, still-empty row must not auto-save.
                document.querySelectorAll('.kesei-closings').forEach(function (cell) {
                    const text = cell.querySelector('.kesei-closings-text');
                    const rows = cell.querySelector('.kesei-closings-rows');
                    const badge = cell.parentElement.querySelector('.kesei-status');

                    function addRow(time, preRun) {
                        const row = document.createElement('div');
                        row.className = 'kesei-closing-row flex items-center gap-1';
                        row.innerHTML =
                            '<input type="time" class="kesei-closing-time flex-1 rounded-md border-gray-300 text-xs focus:border-brand-500 focus:ring-brand-500" value="' + (time || '') + '">' +
                            '<label class="inline-flex items-center gap-1 text-[11px] text-gray-600">' +
                            '<input type="checkbox" class="kesei-closing-mode rounded border-gray-300 text-brand-600 focus:ring-brand-500"' + (preRun ? ' checked' : '') + '> {{ __('Pre') }}</label>' +
                            '<button type="button" class="kesei-closing-remove text-gray-300 hover:text-red-600">&times;</button>';
                        rows.appendChild(row);
                    }

                    cell.querySelector('.kesei-closing-add').addEventListener('click', function () {
                        addRow('', true);
                    });

                    rows.addEventListener('click', function (e) {
                        if (e.target.classList.contains('kesei-closing-remove')) {
                            e.target.closest('.kesei-closing-row').remove();
                        }
                    });

                    cell.querySelector('.kesei-closing-save').addEventListener('click', function () {
                        const closings = Array.from(rows.querySelectorAll('.kesei-closing-row'))
                            .map(function (row) {
                                return {
                                    closing_time: row.querySelector('.kesei-closing-time').value,
                                    closing_mode: row.querySelector('.kesei-closing-mode').checked ? 'pre_run' : 'end_of_day',
                                };
                            })
                            .filter(function (c) { return c.closing_time; });

                        save(cell.dataset.url, { closings: closings }, badge).then(function (d) {
                            if (!d) return;
                            text.textContent = d.closing_label || '—';
                            cell.open = false;
                        });
                    });

                    document.addEventListener('click', function (e) {
                        if (cell.open && !cell.contains(e.target)) cell.open = false;
                    });
                });

                const tbody = document.getElementById('kesei-sortable');
                if (!tbody || !window.Sortable) return;

                const url = tbody.dataset.reorderUrl;
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                const status = document.getElementById('kesei-reorder-status');
                const setStatus = (text, cls) => {
                    if (status) { status.textContent = text; status.className = 'ml-1 text-xs font-medium ' + cls; }
                };
                const renumber = () => {
                    tbody.querySelectorAll('tr[data-id]').forEach((tr, i) => {
                        const cell = tr.querySelector('.kesei-urutan-cell');
                        if (cell) cell.textContent = i + 1;
                    });
                };

                Sortable.create(tbody, {
                    handle: '.kesei-drag-handle',
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
            });
        </script>
    @endpush
</x-app-layout>
