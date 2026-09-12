{{-- Dark-mode twin of _stock-timeline.blade.php — see _timeline-dark.blade.php. --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 px-3 py-1.5 border-b-2 border-white">
        <span class="text-xs font-bold text-white tracking-wide">{{ __('TIMELINE STOK') }}</span>
    </div>

    @if ($keseiRows->isEmpty())
        <div class="flex-1 min-h-0 flex items-center justify-center text-center text-slate-500 text-xs px-3">
            {{ __('Belum ada part di menu Kesei.') }}
        </div>
    @else
        <div class="andon-scroll flex-1 min-h-0 overflow-auto">
            <table class="text-[11px] border-collapse">
                <thead class="sticky top-0 z-10">
                    <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400">
                        <th class="sticky left-0 z-20 bg-black px-2 py-1.5 border-b-2 border-r border-white font-semibold whitespace-nowrap">
                            {{ __('Waktu') }}
                        </th>
                        @foreach ($keseiRows as $row)
                            <th class="bg-black px-2 py-1.5 border-b-2 border-l border-white/20 font-semibold text-right whitespace-nowrap"
                                title="{{ implode(' + ', $row['sources']) }}">
                                {{ $row['label'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stockHistoryRows as $index => $histRow)
                        <tr class="border-b border-white/10 {{ $index % 2 === 1 ? 'bg-white/5' : '' }}">
                            <td class="sticky left-0 z-10 px-2 py-1 text-slate-300 border-r border-white/10 whitespace-nowrap"
                                style="background-color: {{ $index % 2 === 1 ? '#0f0f0f' : '#000000' }};">
                                {{ $histRow['time'] }}
                            </td>
                            @foreach ($keseiRows as $row)
                                @php $cell = $histRow['values'][$row['id']] ?? null; @endphp
                                <td class="px-2 py-1 border-l border-white/10 text-right font-semibold whitespace-nowrap
                                           {{ $cell && $cell['under_min'] ? 'bg-red-500/20 text-red-400' : 'text-slate-200' }}">
                                    {{ $cell['stock'] ?? '-' }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $keseiRows->count() + 1 }}" class="px-2 py-4 text-center text-slate-500">
                                {{ __('Belum ada histori stok terekam.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
