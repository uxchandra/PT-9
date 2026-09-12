{{-- Dark-mode twin of _timeline.blade.php, used only by the standalone
     /andon-kesei and /andon-kesei-scan pages — the light original still
     serves the KESEI card embedded in the pattern-driven /andon board. --}}
@if ($keseiRows->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada part di menu Kesei.') }}
    </div>
@else
    @php $totalWidth = max($timelineEnd - $dayStart, 1) * $pxPerMinute + 24; @endphp
    <div class="andon-scroll h-full overflow-auto bg-black">
        <div class="h-full flex flex-col" style="width: {{ 110 + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-black border-b-2 border-white">
                <div class="sticky left-0 z-40 bg-black shrink-0" style="width: 110px;"></div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-white/10 flex items-center text-[10px] text-slate-300 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ $windowStart->copy()->addMinutes($t)->format('H') }}:00
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Part rows --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                    <div class="grid-line-dark z-0" style="left: {{ 110 + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                {{-- Moving "now" line — nudged forward every second by the page script. --}}
                @if ($nowMinute >= $dayStart && $nowMinute <= $timelineEnd)
                    <div id="kesei-now-line" class="absolute top-0 bottom-0 z-20 pointer-events-none"
                         data-day-start="{{ $dayStart }}" data-px="{{ $pxPerMinute }}" data-now="{{ $nowMinute }}" data-total="{{ $timelineEnd }}"
                         style="left: {{ 110 + ($nowMinute - $dayStart) * $pxPerMinute }}px; width: 2px; background: #3b82f6;"></div>
                @endif

                @foreach ($keseiRows as $row)
                    @php
                        // Active rows alternate two vivid amber shades so a run of them
                        // stays readable and clearly reads as "running now" on a black
                        // board; inactive rows stay flat black and dimmed.
                        $rowBg = $row['runs_today']
                            ? ($loop->even ? 'bg-amber-500/25' : 'bg-amber-600/30')
                            : 'bg-black opacity-40';
                        $labelBg = $row['runs_today']
                            ? ($loop->even ? '#b45309' : '#92400e')
                            : '#000000';
                    @endphp
                    <div class="flex border-b border-white/10 transition-colors {{ $rowBg }}"
                         style="height: 48px;"
                         @unless ($row['runs_today']) title="{{ __('Part ini tidak jalan di pattern yang sedang berjalan') }}" @endunless>
                        <div class="sticky left-0 z-20 flex flex-col justify-center px-3 shrink-0 border-r border-white/10"
                             style="width: 110px; background-color: {{ $labelBg }};">
                            <span class="font-bold text-white text-xs truncate">{{ $row['label'] }}</span>
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
                                    {{-- style before class: keeps the literal "text-green-700" class
                                         (some tests key off it) while actually rendering a brighter
                                         green that reads on black. --}}
                                    <span @if ($ck > 0) style="color: #4ade80; text-shadow: 0 0 4px rgba(74,222,128,0.6);" @endif
                                          class="absolute bottom-0.5 left-1 text-[9px] font-bold leading-none whitespace-nowrap {{ $ck > 0 ? 'text-green-700' : 'text-slate-500' }}">{{ $ck }}</span>
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
                                            <span class="block w-0.5 h-full rounded-sm" style="background-color: #ff3b3b; box-shadow: 0 0 3px rgba(255,59,59,0.9);"></span>
                                        @endfor
                                    </div>
                                    <span class="text-[9px] leading-none font-bold mt-0.5" style="color: #ff5252;">{{ $event['kanban'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
