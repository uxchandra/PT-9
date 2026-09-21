@php
    $beforeMaterial = ['Row', 'Kolom', 'Part No', 'Level'];
    $afterMaterial = ['Perintah Pulling', 'LT/KBN', 'Lot Produksi', 'Slot', 'Avg Slot', 'Slot Fix', 'Loading Time', 'Dandori', 'Jumlah Proses'];
    $rightAligned = ['Perintah Pulling', 'LT/KBN', 'Lot Produksi', 'Slot', 'Avg Slot', 'Slot Fix', 'Loading Time', 'Dandori', 'Jumlah Proses'];
    $cycles = \App\Models\LotMaking::CYCLES;
    $totalColumns = 1 + count($beforeMaterial) + 2 + count($afterMaterial) + count($cycles) + 2;
@endphp

<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $lotMakings->total() }} {{ __('data lot making') }}
</p>

<div class="lm-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 340px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead class="sticky top-0 z-10">
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th rowspan="2" class="align-bottom bg-gray-50 px-4 py-3 border-b-2 border-gray-300">{{ __('No') }}</th>
                @foreach ($beforeMaterial as $label)
                    <th rowspan="2" class="align-bottom bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __($label) }}</th>
                @endforeach
                <th colspan="2" class="bg-gray-50 px-4 py-1.5 border-b border-l border-gray-300 text-center">{{ __('Material') }}</th>
                @foreach ($afterMaterial as $label)
                    <th rowspan="2" class="align-bottom bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 {{ in_array($label, $rightAligned) ? 'text-right' : '' }}">{{ __($label) }}</th>
                @endforeach
                <th colspan="{{ count($cycles) }}" class="bg-gray-50 px-4 py-1.5 border-b border-l border-gray-300 text-center">{{ __('Cycle') }}</th>
                <th rowspan="2" class="align-bottom bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Order/Cycle') }}</th>
                <th rowspan="2" class="align-bottom sticky right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
            </tr>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('No Part') }}</th>
                <th class="bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Level') }}</th>
                @foreach ($cycles as $cycle)
                    <th class="bg-gray-50 px-2 py-3 border-b-2 border-l border-gray-300 w-16 text-center">C{{ $cycle }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($lotMakings as $lotMaking)
                <tr class="group hover:bg-gray-50">
                    <td class="px-4 py-2 border-b border-gray-200 text-gray-500">{{ $lotMaking->no ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-700">{{ $lotMaking->row ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-700">{{ $lotMaking->kolom ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 font-semibold text-gray-800">{{ $lotMaking->part?->part_no ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ \App\Models\LotMaking::LEVELS[$lotMaking->level] ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ $lotMaking->material_part_no ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">{{ $lotMaking->material_level ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->pulling_command ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->lt_per_kbn ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->lot_produksi ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->slot ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->avg_slot !== null ? number_format($lotMaking->avg_slot, 2) : '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right font-semibold text-gray-800">{{ $lotMaking->slot_fix ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->loading_time ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->dandori ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->jumlah_proses ?? '-' }}</td>
                    @foreach ($cycles as $cycle)
                        <td class="px-2 py-2 border-b border-l border-gray-200 text-center {{ $lotMaking->cycleTime($cycle) ? 'font-semibold text-gray-800' : 'text-gray-300' }}">
                            {{ $lotMaking->cycleTime($cycle) ?? '—' }}
                        </td>
                    @endforeach
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->order_per_cycle ?? '-' }}</td>
                    <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right">
                        <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'lot-making-edit-{{ $lotMaking->id }}')"
                                class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</button>
                        <form action="{{ route('lot-makings.destroy', $lotMaking) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus data lot making ini?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $totalColumns }}" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data lot making.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($lotMakings->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $lotMakings->links() }}
    </div>
@endif

{{-- Regenerated alongside the rows on every AJAX search/page change so each
     modal always matches the row currently on screen — Alpine picks up newly
     injected markup automatically, same as the rows themselves. --}}
@foreach ($lotMakings as $lotMaking)
    @include('lot-makings._edit-modal', ['lotMaking' => $lotMaking, 'parts' => $parts])
@endforeach
