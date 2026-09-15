<x-app-layout>
    <x-slot name="header">
        {{ __('Planning') }}
    </x-slot>

    @php
        $statusTabs = \App\Models\LotMakingPlanning::STATUS_LABELS;
        $STATUS_OPEN = \App\Models\LotMakingPlanning::STATUS_OPEN;
        $STATUS_IN_PROGRESS = \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS;
        $STATUS_CLOSE = \App\Models\LotMakingPlanning::STATUS_CLOSE;
    @endphp

    @push('scripts')
        <script>
            // "machine_id:shift" -> [{value: "start:end", label: "14:00 - 16:00"}, ...]
            // on today's board (the only board Lot Making Planning ever
            // targets) — narrows down each pending step's own Waktu Free
            // Time select the moment that same row's own Machine and Shift
            // are both known (shift is picked per step, not per lot, so
            // this never needs to look outside its own row).
            @php
                $clockLabel = fn (int $minute) => sprintf('%02d:%02d', intdiv($minute % 1440, 60), $minute % 60);
            @endphp
            window.__lmpFreeWindows = @json(collect($freeWindows)->groupBy(fn ($w) => $w['machine_id'].':'.$w['shift'])
                ->map(fn ($windows) => $windows->map(fn ($w) => [
                    'value' => $w['start'].':'.$w['end'],
                    'label' => $clockLabel($w['start']).' - '.$clockLabel($w['end']),
                ]))
            );

            function lmpRefreshWindow(row) {
                if (!row) return;
                const machineSelect = row.querySelector('.lmp-machine-select');
                const shiftSelect = row.querySelector('.lmp-shift-select');
                const windowSelect = row.querySelector('.lmp-window-select');
                if (!machineSelect || !shiftSelect || !windowSelect) return;

                const machineId = machineSelect.value;
                const shift = shiftSelect.value;
                const key = machineId && shift ? machineId + ':' + shift : null;
                const windows = (key && window.__lmpFreeWindows[key]) || [];

                windowSelect.innerHTML = '';
                windowSelect.appendChild(new Option('{{ __('Waktu Free Time') }}', ''));
                windows.forEach((w) => windowSelect.appendChild(new Option(w.label, w.value)));
                windowSelect.disabled = windows.length === 0;
            }

            document.addEventListener('change', function (e) {
                if (e.target.matches('.lmp-machine-select, .lmp-shift-select')) {
                    lmpRefreshWindow(e.target.closest('tr'));
                }
            });

            // A row whose Machine select already has a default option
            // selected (see Assignment Machine) never fires its own
            // 'change' event on page load, so its Waktu Free Time select
            // would otherwise sit stuck on disabled until the user happens
            // to touch that dropdown — compute it for every pending row up
            // front instead.
            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.lmp-machine-select').forEach((select) => {
                    lmpRefreshWindow(select.closest('tr'));
                });
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

            <p class="px-6 pt-4 text-sm text-gray-500">
                {{ $groupsPage->total() }} {{ __('data planning') }}
            </p>

            <div class="mx-6 my-6 overflow-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 340px);">
                <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-gray-300">{{ __('Created At') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Lot') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Shift') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Proses') }}</th>
                            <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Machine') }}</th>
                            @if ($status === $STATUS_OPEN)
                                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Waktu Free Time') }}</th>
                                <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
                            @elseif ($status === $STATUS_IN_PROGRESS)
                                <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
                            @else
                                <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300">{{ __('Closed At') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groupsPage as $group)
                            @php
                                $planning = $group['planning'];
                                $steps = $group['steps'];
                                $rowCount = count($steps);
                                $defaults = $defaultsByPart->get($planning->part_id, collect());
                            @endphp
                            @foreach ($steps as $i => $step)
                                <tr class="group hover:bg-gray-50 {{ $i === 0 ? 'border-t-2 border-gray-300' : '' }}">
                                    @if ($i === 0)
                                        <td rowspan="{{ $rowCount }}" class="px-4 py-2 border-b border-gray-200 text-gray-600 align-top">{{ $planning->created_at->format('d/m/Y H:i') }}</td>
                                        <td rowspan="{{ $rowCount }}" class="px-4 py-2 border-b border-l border-gray-200 align-top">
                                            <div class="font-semibold text-gray-800">{{ $planning->part?->part_no ?? '(part terhapus)' }}</div>
                                        </td>
                                        <td rowspan="{{ $rowCount }}" class="px-4 py-2 border-b border-l border-gray-200 text-gray-600 align-top">{{ $planning->lot }}</td>
                                    @endif

                                    @if ($step['proses'] === null)
                                        <td colspan="5" class="px-4 py-2 border-b border-l border-gray-200 text-amber-600">
                                            {{ __('Jumlah Proses part ini belum diisi.') }}
                                            <a href="{{ route('lot-makings.index') }}" class="font-medium underline">{{ __('Isi di Lot Making') }}</a>
                                        </td>
                                    @else
                                        @php
                                            $n = $step['proses'];
                                            $jumlahProses = $step['jumlahProses'];
                                            $assignment = $step['assignment'];
                                        @endphp

                                        @if ($status === $STATUS_OPEN)
                                            @php $formId = 'lmp-assign-'.$planning->id.'-'.$n; @endphp
                                            <td class="px-4 py-2 border-b border-l border-gray-200">
                                                <select form="{{ $formId }}" name="shift" required
                                                        class="lmp-shift-select rounded-lg border-gray-300 text-xs focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Shift') }}</option>
                                                    @foreach (\App\Models\Pattern::SHIFT_LABELS as $value => $label)
                                                        <option value="{{ $value }}">{{ __('Shift') }} {{ $value }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600 tabular-nums">{{ $n }}/{{ $jumlahProses }}</td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200">
                                                <select form="{{ $formId }}" name="machine_id" required
                                                        class="lmp-machine-select rounded-lg border-gray-300 text-xs focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Machine') }}</option>
                                                    @foreach ($allMachines as $machine)
                                                        @php $isDefault = $machine->id === $defaults->get($n)?->machine_id; @endphp
                                                        <option value="{{ $machine->id }}" @selected($isDefault)>
                                                            {{ $machine->name }}{{ $isDefault ? ' ('.__('standar').')' : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200">
                                                <select form="{{ $formId }}" name="window" required disabled
                                                        class="lmp-window-select rounded-lg border-gray-300 text-xs focus:ring-brand-700 focus:border-brand-700">
                                                    <option value="">{{ __('Waktu Free Time') }}</option>
                                                </select>
                                            </td>
                                            <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right">
                                                <form id="{{ $formId }}" method="POST" action="{{ route('lot-making-plannings.assign', $planning) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="proses" value="{{ $n }}">
                                                    <button type="submit" @disabled(! $todaysBoard)
                                                            class="px-3 py-1.5 bg-brand-800 rounded-lg font-semibold text-xs text-white hover:bg-brand-900 disabled:opacity-50">
                                                        {{ __('Assign') }}
                                                    </button>
                                                </form>
                                            </td>
                                        @elseif ($status === $STATUS_IN_PROGRESS)
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ __('Shift') }} {{ $assignment->shift }}</td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600 tabular-nums">{{ $n }}/{{ $jumlahProses }}</td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ $assignment->machine?->name ?? '-' }}</td>
                                            <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right whitespace-nowrap">
                                                <form method="POST" action="{{ route('lot-making-plannings.cancel', $assignment) }}" class="inline"
                                                      onsubmit="return confirm('{{ __('Batalkan proses ini? Slot Andon kembali FREE TIME.') }}');">
                                                    @csrf
                                                    <button type="submit" class="text-amber-600 hover:text-amber-800 font-medium">{{ __('Batal') }}</button>
                                                </form>
                                                <form method="POST" action="{{ route('lot-making-plannings.close', $assignment) }}" class="inline ml-3"
                                                      onsubmit="return confirm('{{ __('Selesaikan proses ini? Slot Andon kembali FREE TIME.') }}');">
                                                    @csrf
                                                    <button type="submit" class="text-red-600 hover:text-red-800 font-medium">{{ __('Close') }}</button>
                                                </form>
                                            </td>
                                        @else
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ __('Shift') }} {{ $assignment->shift }}</td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600 tabular-nums">{{ $n }}/{{ $jumlahProses }}</td>
                                            <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ $assignment->machine?->name ?? '-' }}</td>
                                            <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-gray-600">{{ $assignment->finished_at->format('d/m/Y H:i') }}</td>
                                        @endif
                                    @endif
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-400">
                                    @if ($status === $STATUS_OPEN)
                                        {{ __('Belum ada proses yang menunggu dijadwalkan.') }}
                                    @elseif ($status === $STATUS_IN_PROGRESS)
                                        {{ __('Belum ada proses yang sedang berjalan di Andon.') }}
                                    @else
                                        {{ __('Belum ada proses yang ditutup.') }}
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($groupsPage->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $groupsPage->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
