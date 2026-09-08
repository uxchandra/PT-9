<p class="px-6 pt-4 text-sm text-gray-500">
    {{ $partNos->count() }} {{ __('part') }} &middot; {{ $rows->count() }} {{ __('waktu capture') }} &middot; {{ $dateLabel }}
</p>

@if ($partNos->isEmpty())
    <div class="mx-6 my-10 text-center text-sm text-gray-400">
        {{ $search !== '' ? __('Tidak ada snapshot yang cocok untuk tanggal ini.') : __('Belum ada snapshot terekam untuk tanggal ini.') }}
    </div>
@else
    <div class="ss-table-scroll mx-6 my-6 overflow-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 320px);">
        <table class="min-w-max text-xs border-separate border-spacing-0 whitespace-nowrap">
            <thead>
                <tr class="bg-gray-50 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                    <th class="sticky top-0 left-0 z-20 bg-gray-50 px-3 py-2 border-b-2 border-r border-gray-300">{{ __('Waktu') }}</th>
                    @foreach ($partNos as $partNo)
                        <th class="sticky top-0 z-10 bg-gray-50 px-3 py-2 border-b-2 border-l border-gray-200 text-right">{{ $partNo }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $index => $row)
                    <tr class="{{ $index % 2 === 1 ? 'bg-gray-50/60' : '' }}">
                        <td class="sticky left-0 z-10 px-3 py-1 text-gray-600 border-b border-r border-gray-200"
                            style="background-color: {{ $index % 2 === 1 ? '#f8fafc' : '#ffffff' }};">
                            {{ $row['time'] }}
                        </td>
                        @foreach ($partNos as $partNo)
                            @php $cell = $row['values'][$partNo] ?? null; @endphp
                            <td class="px-3 py-1 border-b border-l border-gray-100 text-right font-semibold
                                       {{ $cell && $cell['under_min'] ? 'bg-red-50 text-red-600' : 'text-gray-700' }}">
                                {{ $cell['stock'] ?? '-' }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
