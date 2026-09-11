@if ($keseiRows->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-400">
        {{ __('Belum ada part di menu Kesei.') }}
    </div>
@else
    @php $totalWidth = max($timelineEnd - $dayStart, 1) * $pxPerMinute + 24; @endphp
    <div class="andon-scroll h-full overflow-auto">
        <div class="h-full flex flex-col" style="width: {{ 110 + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-slate-50 border-b border-slate-300">
                <div class="sticky left-0 z-40 bg-slate-50 shrink-0" style="width: 110px;"></div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-slate-200 flex items-center text-[10px] text-slate-500 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ $windowStart->copy()->addMinutes($t)->format('H') }}:00
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Part rows --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                    <div class="grid-line z-0" style="left: {{ 110 + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                {{-- Moving "now" line — nudged forward every second by the page script. --}}
                @if ($nowMinute >= $dayStart && $nowMinute <= $timelineEnd)
                    <div id="kesei-now-line" class="absolute top-0 bottom-0 z-20 pointer-events-none"
                         data-day-start="{{ $dayStart }}" data-px="{{ $pxPerMinute }}" data-now="{{ $nowMinute }}" data-total="{{ $timelineEnd }}"
                         style="left: {{ 110 + ($nowMinute - $dayStart) * $pxPerMinute }}px; width: 2px; background: #2563eb;"></div>
                @endif

                @foreach ($keseiRows as $row)
                    @php
                        // Active rows alternate light/dark yellow so a run of them stays readable.
                        $rowBg = $row['runs_today']
                            ? ($loop->even ? 'bg-amber-50' : 'bg-amber-100')
                            : (($loop->even ? 'bg-slate-50/60' : 'bg-white').' opacity-40');
                        $labelBg = $row['runs_today']
                            ? ($loop->even ? '#fef3c7' : '#fde68a')
                            : ($loop->even ? '#f8fafc' : '#ffffff');
                    @endphp
                    <div class="flex border-b border-slate-200 transition-colors {{ $rowBg }}"
                         style="height: 48px;"
                         @unless ($row['runs_today']) title="{{ __('Part ini tidak jalan di pattern yang sedang berjalan') }}" @endunless>
                        <div class="sticky left-0 z-20 flex flex-col justify-center px-3 shrink-0"
                             style="width: 110px; background-color: {{ $labelBg }};">
                            <span class="font-bold text-slate-700 text-xs truncate">{{ $row['label'] }}</span>
                            @if (count($row['sources']) > 1 || ($row['sources'][0] ?? null) !== $row['label'])
                                <span class="text-[9px] text-slate-400 truncate" title="{{ implode(', ', $row['sources']) }}">&sum; {{ implode(', ', $row['sources']) }}</span>
                            @endif
                        </div>
                        <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                            @php
                                // Live count of the red ticks currently on this row — resets to
                                // 0 the moment the (most recent) closing folds them away.
                                $ck = collect($stockDecreaseEvents[$row['id']] ?? [])->sum('kanban');
                            @endphp
                            {{-- A row can carry more than one closing time a day (e.g. 05:00
                                 and 15:00) — one marker per definition. --}}
                            @foreach ($row['closing_markers'] as $marker)
                                <div class="closing-time-marker absolute top-0 bottom-0 z-10"
                                     style="left: {{ max(0, ($marker['minute'] - $dayStart) * $pxPerMinute) }}px;"
                                     title="{{ __('Closing time') }} {{ $marker['label'] }} — {{ $ck }} kanban berjalan">
                                    <span class="absolute bottom-0.5 left-1 text-[9px] font-bold leading-none whitespace-nowrap {{ $ck > 0 ? 'text-green-700' : 'text-slate-400' }}">{{ $ck }}</span>
                                </div>
                            @endforeach
                            @foreach ($stockDecreaseEvents[$row['id']] ?? [] as $event)
                                {{-- The controller already drops ticks that folded at the last run-day
                                     closing; whatever is left here is the live pile and always shows. --}}
                                <div class="absolute top-1 bottom-0.5 z-10 flex flex-col items-center"
                                     style="left: {{ ($event['minute'] - $dayStart) * $pxPerMinute }}px;"
                                     title="{{ $event['time'] }} — stok turun {{ $event['kanban'] }} kanban ({{ $event['pcs'] }} pcs)">
                                    <div class="flex-1 flex items-end gap-px">
                                        @for ($i = 0; $i < $event['kanban']; $i++)
                                            <span class="block w-0.5 h-full bg-red-500 rounded-sm"></span>
                                        @endfor
                                    </div>
                                    <span class="text-[9px] leading-none font-bold text-red-600 mt-0.5">{{ $event['kanban'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
