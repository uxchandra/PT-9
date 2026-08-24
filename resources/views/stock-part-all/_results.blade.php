<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $stockParts->total() }} {{ __('part ditemukan') }}
</p>

@if ($error)
    <div class="mx-6 mt-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 font-medium text-sm text-red-700">
        <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/>
        </svg>
        <span>{{ $error }}</span>
    </div>
@endif

<div class="stock-table-scroll mx-6 my-6 overflow-x-auto overflow-y-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(105vh - 380px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-gray-300">{{ __('Store') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Rak') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Process') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Line') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part No') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Customer') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Std Min') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Stock') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 text-right">{{ __('Stock Prod') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Update In') }}</th>
                <th class="sticky top-0 z-10 bg-gray-50 px-6 py-3 border-b-2 border-l border-gray-300">{{ __('Update Out') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stockParts as $row)
                @php
                    $stock = is_numeric($row['stock'] ?? null) ? (float) $row['stock'] : null;
                    $stdMin = is_numeric($row['std_min'] ?? null) ? (float) $row['std_min'] : null;
                    $isUnderMin = $stock !== null && $stdMin !== null && $stock < $stdMin;
                @endphp
                <tr class="hover:bg-gray-50 {{ $isUnderMin ? 'bg-red-50/60' : '' }}">
                    <td class="px-6 py-3 border-b border-gray-300 text-gray-800">{{ $row['store'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $row['rack_no'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $row['process'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $row['line'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-800 font-medium">{{ $row['part_no'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-600">{{ $row['customer'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right text-gray-600">{{ $row['std_min'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right font-semibold {{ $isUnderMin ? 'text-red-600' : 'text-gray-800' }}">
                        {{ $row['stock'] ?? '-' }}
                    </td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-right text-gray-600">{{ $row['stock_prod'] ?? '-' }}</td>
                    <td class="px-4 py-3 border-b border-l border-gray-300 text-gray-500 text-xs">
                        {{ $row['last_update_in'] ?? '-' }}
                        @if (!empty($row['pic_update_in']))
                            <br><span class="text-gray-400">{{ $row['pic_update_in'] }}</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 border-b border-l border-gray-300 text-gray-500 text-xs">
                        {{ $row['last_update_out'] ?? '-' }}
                        @if (!empty($row['pic_update_out']))
                            <br><span class="text-gray-400">{{ $row['pic_update_out'] }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="px-6 py-8 text-center text-gray-400">{{ __('Tidak ada data stock part.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($stockParts->hasPages())
    <div class="px-6 py-4 border-t border-gray-100">
        {{ $stockParts->links() }}
    </div>
@endif
