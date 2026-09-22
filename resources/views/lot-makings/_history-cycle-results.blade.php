<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $cycles->total() }} {{ __('lot selesai') }}
</p>

<div class="kesei-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(105vh - 380px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-gray-300">{{ __('Selesai Pada') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part No') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Lot') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Status') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-l border-gray-300">{{ __('Mesin') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cycles as $cycle)
                @php
                    $statusLabel = match ($cycle->history_status) {
                        \App\Models\LotMakingPlanning::STATUS_OPEN => __('Open'),
                        \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS => __('In Progress'),
                        \App\Models\LotMakingPlanning::STATUS_CLOSE => __('Close'),
                        default => null,
                    };
                    $statusColor = match ($cycle->history_status) {
                        \App\Models\LotMakingPlanning::STATUS_OPEN => 'bg-amber-100 text-amber-700',
                        \App\Models\LotMakingPlanning::STATUS_IN_PROGRESS => 'bg-blue-100 text-blue-700',
                        \App\Models\LotMakingPlanning::STATUS_CLOSE => 'bg-green-100 text-green-700',
                        default => 'bg-gray-100 text-gray-500',
                    };
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 border-b border-gray-300 text-gray-800">{{ $cycle->completed_at?->format('d M Y H:i:s') ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-800 font-medium">{{ $cycle->part_no }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $cycle->lot_produksi }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300">
                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusColor }}">{{ $statusLabel ?? '-' }}</span>
                    </td>
                    <td class="px-6 py-3 border-b border-l border-gray-300 text-gray-600">
                        {{ $cycle->history_machines->isNotEmpty() ? $cycle->history_machines->implode(', ') : '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data history.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($cycles->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $cycles->links() }}
    </div>
@endif
