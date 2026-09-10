<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $scans->total() }} {{ __('scan') }}
</p>

<div class="kesei-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(105vh - 380px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-gray-300">{{ __('Waktu Scan') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part No') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Lokasi') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Oleh') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-l border-gray-300">{{ __('QR Mentah') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($scans as $scan)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 border-b border-gray-300 text-gray-800">{{ $scan->scanned_at?->format('d M Y H:i:s') ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-800 font-medium">{{ $scan->part_no }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $scan->location }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $scan->scannedBy?->name ?? '-' }}</td>
                    <td class="px-6 py-3 border-b border-l border-gray-300 text-gray-400">{{ $scan->raw }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data scan.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($scans->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $scans->links() }}
    </div>
@endif
