<x-app-layout>
    <x-slot name="header">
        {{ __('Planning') }}
    </x-slot>

    @php
        $statusTabs = \App\Models\LotMakingPlanning::STATUS_LABELS;
    @endphp

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
            .select2-container--disabled .select2-selection--single { background-color: #f3f4f6; }
        </style>
    @endpush

    @push('scripts')
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            // "machine_id:shift" -> [{value: "start:end", label: "14:00 - 16:00"}, ...]
            // on today's board (the only board Lot Making Planning ever
            // targets) — narrows down the "Waktu Free Time" select the
            // moment both Machine and Shift are picked in a row.
            @php
                $clockLabel = fn (int $minute) => sprintf('%02d:%02d', intdiv($minute % 1440, 60), $minute % 60);
            @endphp
            window.__lmpFreeWindows = @json(collect($freeWindows)->groupBy(fn ($w) => $w['machine_id'].':'.$w['shift'])
                ->map(fn ($windows) => $windows->map(fn ($w) => [
                    'value' => $w['start'].':'.$w['end'],
                    'label' => $clockLabel($w['start']).' - '.$clockLabel($w['end']),
                ]))
            );

            function lmpInitSelect2($el) {
                if (!window.jQuery || !jQuery.fn.select2) return;
                const $select = jQuery($el);
                if ($select.data('select2')) $select.select2('destroy');
                $select.select2({ width: '170px', placeholder: $el.dataset.placeholder || '', allowClear: true });
            }

            function lmpRefreshWindows(form) {
                const machineSelect = form.querySelector('.lmp-machine-select');
                const shiftSelect = form.querySelector('.lmp-shift-select');
                const windowSelect = form.querySelector('.lmp-window-select');
                const machineId = machineSelect.selectedOptions[0]?.dataset.machineId;
                const shift = shiftSelect.value;
                const key = machineId && shift ? machineId + ':' + shift : null;
                const windows = (key && window.__lmpFreeWindows[key]) || [];

                windowSelect.innerHTML = '';
                windowSelect.appendChild(new Option('{{ __('Waktu Free Time') }}', ''));
                windows.forEach((w) => windowSelect.appendChild(new Option(w.label, w.value)));
                windowSelect.disabled = windows.length === 0;

                lmpInitSelect2(windowSelect);
            }

            window.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.lmp-machine-select, .lmp-window-select').forEach(lmpInitSelect2);

                // Select2 changes the Machine select's value through jQuery's
                // own event system (`.trigger('change')`), which a plain
                // document.addEventListener('change', ...) does not reliably
                // catch — picking Machine before Shift silently failed to
                // refresh the Waktu Free Time list until Shift was touched
                // again. Delegating through jQuery instead catches both the
                // Select2-driven Machine change and the native Shift change.
                if (window.jQuery) {
                    jQuery(document).on('change', '.lmp-machine-select, .lmp-shift-select', function (e) {
                        lmpRefreshWindows(e.target.closest('form'));
                    });
                }
            });
        </script>
    @endpush

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-4 p-6 border-b border-gray-100">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">{{ __('Lot Making Planning') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        @if ($todaysBoard)
                            {{ __('Pattern hari ini') }}: <span class="font-semibold text-gray-700">{{ $todaysBoard->name }}</span>
                        @else
                            <span class="text-amber-600">{{ __('Belum ada pattern yang jalan hari ini di Calendar — assign belum bisa dilakukan.') }}</span>
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-2 rounded-lg border border-gray-200 p-1 text-sm">
                    @foreach ($statusTabs as $value => $label)
                        <a href="{{ route('lot-making-plannings.index', ['status' => $value]) }}"
                           class="px-3 py-1.5 rounded-md font-medium {{ $status === $value ? 'bg-brand-800 text-white' : 'text-gray-600 hover:bg-gray-50' }}">
                            {{ $label }}
                        </a>
                    @endforeach
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

            @if ($errors->any())
                <div class="mx-6 mt-6 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-6 py-3">{{ __('Created At') }}</th>
                            <th class="px-6 py-3">{{ __('Part') }}</th>
                            <th class="px-6 py-3">{{ __('Lot') }}</th>
                            @if ($status === \App\Models\LotMakingPlanning::STATUS_OPEN)
                                <th class="px-6 py-3">{{ __('Pattern') }}</th>
                                <th class="px-6 py-3 min-w-[560px]">{{ __('Assign ke') }}</th>
                            @elseif ($status === \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS)
                                <th class="px-6 py-3">{{ __('Pattern Board') }}</th>
                                <th class="px-6 py-3">{{ __('Machine') }}</th>
                                <th class="px-6 py-3">{{ __('Proses') }}</th>
                                <th class="px-6 py-3 w-40 text-right">{{ __('Aksi') }}</th>
                            @else
                                <th class="px-6 py-3">{{ __('Pattern Board') }}</th>
                                <th class="px-6 py-3">{{ __('Machine') }}</th>
                                <th class="px-6 py-3">{{ __('Proses') }}</th>
                                <th class="px-6 py-3">{{ __('Closed At') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($plannings as $planning)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ $planning->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-6 py-3 text-gray-800 font-medium">{{ $planning->part?->part_no ?? '(part terhapus)' }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $planning->lot }}</td>

                                @if ($status === \App\Models\LotMakingPlanning::STATUS_OPEN)
                                    @php $options = $assignmentsByPart->get($planning->part_id, collect()); @endphp
                                    <td class="px-6 py-3 text-gray-600">{{ $todaysBoard?->name ?? '-' }}</td>
                                    @if (! $todaysBoard)
                                        <td class="px-6 py-3 text-gray-400">{{ __('Belum ada pattern hari ini.') }}</td>
                                    @elseif ($options->isEmpty())
                                        <td class="px-6 py-3 text-gray-400">
                                            {{ __('Belum ada Assignment Machine untuk part ini.') }}
                                            <a href="{{ route('lot-makings.index') }}#assignment-machine" class="text-brand-700 hover:text-brand-900 font-medium underline">{{ __('Daftarkan') }}</a>
                                        </td>
                                    @else
                                        <td class="px-6 py-3">
                                            @php $jumlahProses = $planning->part?->lotMaking?->jumlah_proses; @endphp
                                            <form method="POST" action="{{ route('lot-making-plannings.assign', $planning) }}" class="flex flex-nowrap items-center gap-2">
                                                @csrf
                                                @method('PUT')
                                                <select name="shift" required
                                                        class="lmp-shift-select shrink-0 rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Shift') }}</option>
                                                    @foreach (\App\Models\Pattern::SHIFT_LABELS as $value => $label)
                                                        <option value="{{ $value }}">{{ __('Shift') }} {{ $value }}</option>
                                                    @endforeach
                                                </select>
                                                <select name="lot_making_assignment_id" required data-placeholder="{{ __('Cari machine...') }}"
                                                        class="lmp-machine-select shrink-0 rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Machine') }}</option>
                                                    @foreach ($options as $option)
                                                        <option value="{{ $option->id }}" data-machine-id="{{ $option->machine_id }}">
                                                            {{ $option->machine?->name }} - {{ $option->proses }}{{ $jumlahProses ? '/'.$jumlahProses : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <select name="window" required disabled
                                                        class="lmp-window-select shrink-0 rounded-lg border-gray-300 text-sm focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Waktu Free Time') }}</option>
                                                </select>
                                                <button type="submit"
                                                        class="shrink-0 px-3 py-1.5 bg-brand-800 rounded-lg font-semibold text-xs text-white hover:bg-brand-900">
                                                    {{ __('Assign') }}
                                                </button>
                                            </form>
                                        </td>
                                    @endif
                                @elseif ($status === \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS)
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->patternBoard?->name ?? '-' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->machine?->name ?? '-' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->proses ?? '-' }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap">
                                        <form method="POST" action="{{ route('lot-making-plannings.cancel', $planning) }}" class="inline"
                                              onsubmit="return confirm('{{ __('Batalkan assignment ini? Item kembali ke Open dan slot Andon kembali FREE TIME.') }}');">
                                            @csrf
                                            <button type="submit" class="text-amber-600 hover:text-amber-800 font-medium">{{ __('Cancel') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('lot-making-plannings.finish', $planning) }}" class="inline ml-3"
                                              onsubmit="return confirm('{{ __('Tutup planning ini? Balok di Andon akan kembali FREE TIME.') }}');">
                                            @csrf
                                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('Close') }}</button>
                                        </form>
                                    </td>
                                @else
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->patternBoard?->name ?? '-' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->machine?->name ?? '-' }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $planning->proses ?? '-' }}</td>
                                    <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ $planning->finished_at->format('d/m/Y H:i') }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-400">
                                    @if ($status === \App\Models\LotMakingPlanning::STATUS_OPEN)
                                        {{ __('Belum ada lot yang menunggu dijadwalkan.') }}
                                    @elseif ($status === \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS)
                                        {{ __('Belum ada yang sedang berjalan di Andon.') }}
                                    @else
                                        {{ __('Belum ada planning yang ditutup.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($plannings->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $plannings->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
