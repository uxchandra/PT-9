@php
    $columns = [
        'Row', 'Kolom', 'Part No', 'Lot Produksi', 'Slot', 'Avg Slot', 'Slot Fix',
    ];
@endphp

<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $lotMakings->total() }} {{ __('data lot making') }}
</p>

<div class="mx-6 my-6 overflow-x-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 340px);">
    <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-gray-300">{{ __('No') }}</th>
                @foreach ($columns as $label)
                    <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300 {{ in_array($label, ['Lot Produksi', 'Slot', 'Avg Slot', 'Slot Fix']) ? 'text-right' : '' }}">{{ __($label) }}</th>
                @endforeach
                <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lotMakings as $lotMaking)
                <tr class="group hover:bg-gray-50">
                    <td class="px-4 py-2 border-b border-gray-200 text-gray-500">{{ $lotMaking->no ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-700">{{ $lotMaking->row ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-700">{{ $lotMaking->kolom ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 font-semibold text-gray-800">{{ $lotMaking->part?->part_no ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->lot_produksi ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->slot ?? '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right text-gray-600">{{ $lotMaking->avg_slot !== null ? number_format($lotMaking->avg_slot, 2) : '-' }}</td>
                    <td class="px-4 py-2 border-b border-l border-gray-200 text-right font-semibold text-gray-800">{{ $lotMaking->slot_fix ?? '-' }}</td>
                    <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right">
                        <a href="{{ route('lot-makings.edit', $lotMaking) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                        <form action="{{ route('lot-makings.destroy', $lotMaking) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus data lot making ini?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + 2 }}" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data lot making.') }}</td>
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
