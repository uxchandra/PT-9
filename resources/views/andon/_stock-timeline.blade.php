<div class="h-full flex flex-col">
    <div class="shrink-0 px-3 py-1.5 border-b border-slate-300 bg-slate-50">
        <span class="text-xs font-bold text-slate-600 tracking-wide">{{ __('TIMELINE STOK') }}</span>
    </div>

    @if ($koseiParts->isEmpty())
        <div class="flex-1 min-h-0 flex items-center justify-center text-center text-slate-400 text-xs px-3">
            {{ __('Belum ada part yang di-assign untuk board ini.') }}
        </div>
    @else
        <div class="andon-scroll flex-1 min-h-0 overflow-auto">
            <table class="text-[11px] border-collapse">
                <thead class="sticky top-0 z-10">
                    <tr class="text-left text-[10px] uppercase tracking-wide text-slate-500">
                        <th class="sticky left-0 z-20 bg-slate-50 px-2 py-1.5 border-b border-r border-slate-300 font-semibold whitespace-nowrap">
                            {{ __('Waktu') }}
                        </th>
                        @foreach ($koseiParts as $part)
                            <th class="bg-slate-50 px-2 py-1.5 border-b border-l border-slate-200 font-semibold text-right whitespace-nowrap">
                                {{ $part->part_no }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockHistoryRows as $index => $row)
                        <tr class="border-b border-slate-100 {{ $index % 2 === 1 ? 'bg-slate-50/60' : '' }}">
                            <td class="sticky left-0 z-10 px-2 py-1 text-slate-600 border-r border-slate-200 whitespace-nowrap"
                                style="background-color: {{ $index % 2 === 1 ? '#f8fafc' : '#ffffff' }};">
                                {{ $row['time'] }}
                            </td>
                            @foreach ($koseiParts as $part)
                                @php $cell = $row['values'][$part->id] ?? null; @endphp
                                <td class="px-2 py-1 border-l border-slate-100 text-right font-semibold whitespace-nowrap
                                           {{ $cell && $cell['under_min'] ? 'bg-red-50 text-red-600' : 'text-slate-700' }}">
                                    {{ $cell['stock'] ?? '-' }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $koseiParts->count() + 1 }}" class="px-2 py-4 text-center text-slate-400">
                                {{ __('Belum ada histori stok terekam.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
