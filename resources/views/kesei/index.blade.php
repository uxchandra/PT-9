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
                <a href="{{ route('kesei.import.create') }}"
                   class="inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                    {{ __('Import') }}
                </a>
                <a href="{{ route('andon-kesei.show') }}" target="_blank"
                   class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-800 rounded-lg font-semibold text-sm text-white hover:bg-gray-900 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    {{ __('Lihat Andon Kesei') }}
                </a>
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

        {{-- Add part --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
            <form method="POST" action="{{ route('kesei.store') }}" class="flex flex-wrap items-start gap-3">
                @csrf
                <div class="flex-1 min-w-[240px]">
                    <select name="part_id" class="js-select2 block w-full" required>
                        <option value="">{{ __('— pilih part —') }}</option>
                        @foreach ($availableParts as $part)
                            <option value="{{ $part->id }}" @selected((int) old('part_id') === $part->id)>{{ $part->part_no }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
                </div>
                <div class="min-w-[220px]">
                    <input type="text" name="stock_source" value="{{ old('stock_source') }}"
                           placeholder="{{ __('SOS Code (opsional)') }}"
                           class="block w-full rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('part no, pisah koma. Kosong = pakai part no sendiri') }}</p>
                    <x-input-error :messages="$errors->get('stock_source')" class="mt-1" />
                </div>
                <div>
                    <input type="text" name="level" value="{{ old('level') }}" placeholder="{{ __('Level') }}"
                           class="block w-24 rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('Level (opsional)') }}</p>
                    <x-input-error :messages="$errors->get('level')" class="mt-1" />
                </div>
                <div>
                    <input type="time" name="closing_time" value="{{ old('closing_time') }}"
                           class="block rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('Closing time') }}</p>
                    <x-input-error :messages="$errors->get('closing_time')" class="mt-1" />
                </div>
                <div>
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 pt-1.5">
                        <input type="hidden" name="closing_mode" value="end_of_day">
                        <input type="checkbox" name="closing_mode" value="pre_run"
                               @checked(old('closing_mode', 'pre_run') === 'pre_run')
                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        {{ __('Pre-run') }}
                    </label>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Closing sebelum run') }}</p>
                    <x-input-error :messages="$errors->get('closing_mode')" class="mt-1" />
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pt-1.5">
                        @forelse ($patternBoards as $board)
                            <label class="inline-flex items-center gap-1 text-sm text-gray-700">
                                <input type="checkbox" name="pattern_board_ids[]" value="{{ $board->id }}"
                                       @checked(collect(old('pattern_board_ids', []))->map('intval')->contains($board->id))
                                       class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                {{ $board->name }}
                            </label>
                        @empty
                            <span class="text-xs text-gray-400">{{ __('Belum ada pattern board') }}</span>
                        @endforelse
                    </div>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Pattern (bisa lebih dari satu)') }}</p>
                    <x-input-error :messages="$errors->get('pattern_board_ids')" class="mt-1" />
                </div>
                <x-primary-button>{{ __('Tambah') }}</x-primary-button>
            </form>
        </div>

        {{-- List --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-100">
                <p class="text-sm text-gray-500">
                    {{ $keseiParts->count() }} {{ __('part') }}
                    <span id="kesei-reorder-status" class="ml-1 text-xs font-medium"></span>
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-3 py-3 w-8"></th>
                            <th class="px-6 py-3">{{ __('No') }}</th>
                            <th class="px-3 py-3 w-28 whitespace-nowrap">{{ __('Part No') }}</th>
                            <th class="px-3 py-3 w-20 whitespace-nowrap">{{ __('Level') }}</th>
                            <th class="px-6 py-3">{{ __('SOS Code') }}</th>
                            <th class="px-6 py-3">{{ __('Closing Time') }}</th>
                            <th class="px-4 py-3 w-24 text-center whitespace-nowrap">{{ __('Pre-run') }}</th>
                            <th class="px-4 py-3 w-40">{{ __('Pattern') }}</th>
                            <th class="px-3 py-3 w-12 text-center">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody id="kesei-sortable" class="divide-y divide-gray-100"
                           data-reorder-url="{{ route('kesei.reorder') }}">
                        @forelse ($keseiParts as $item)
                            <tr class="hover:bg-gray-50" data-id="{{ $item->id }}">
                                <td class="kesei-drag-handle px-3 py-3 text-center text-gray-300 hover:text-gray-500 cursor-grab select-none"
                                    title="{{ __('Geser untuk mengatur urutan') }}">⠿</td>
                                <td class="kesei-urutan-cell px-6 py-3 text-gray-600">{{ $loop->iteration }}</td>
                                <td class="px-3 py-3 w-28 whitespace-nowrap text-gray-800 font-medium">{{ $item->part?->part_no ?? '-' }}</td>
                                <td class="px-3 py-3 w-20">
                                    <input type="text"
                                           class="kesei-inline w-16 rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="level"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->level }}"
                                           placeholder="—">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-6 py-3">
                                    <input type="text"
                                           class="kesei-inline w-full max-w-xs rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="stock_source"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->stock_source }}">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-6 py-3">
                                    <input type="time"
                                           class="kesei-inline rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm"
                                           data-field="closing_time"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           value="{{ $item->closing_time?->format('H:i') }}">
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-4 py-3 w-24 text-center" title="{{ __('Checklist = closing sebelum run. Kosong = closing di akhir produksi.') }}">
                                    <input type="checkbox"
                                           class="kesei-inline rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                           data-field="closing_mode"
                                           data-checked-value="pre_run"
                                           data-unchecked-value="end_of_day"
                                           data-url="{{ route('kesei.update', $item) }}"
                                           @checked($item->isPreRunClosing())>
                                    <span class="kesei-status ml-1 text-xs"></span>
                                </td>
                                <td class="px-4 py-3 w-40">
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
                                <td class="px-3 py-3 text-center">
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
                                <td colspan="9" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada part di Kesei.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('styles')
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <style>
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

                // Inline auto-save of Source / Closing Time / Mode.
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
                                if (field.dataset.field === 'closing_time') field.value = d.closing_time ?? '';
                                if (field.dataset.field === 'closing_mode' && field.type === 'checkbox') field.checked = (d.closing_mode ?? 'pre_run') === 'pre_run';
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
