{{-- Kesei timeline for the combined Andon Production Line 9 board — works
     backwards from the standalone /andon-kesei timeline (_timeline-dark.
     blade.php): there the "now" line moves across a fixed-position full-day
     timeline; here the "now" line stays PUT on screen (1 hour's worth of
     content to its left, i.e. showing 06:00-ish → now, and whatever fits to
     its right, i.e. now → +3h-ish before you need to scroll further) and the
     timeline itself scrolls underneath it — see the ticker in
     andon-production/show.blade.php, which continuously sets this panel's
     own scrollLeft instead of animating the line's position. --}}
@if ($keseiRows->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada part di menu Kesei.') }}
    </div>
@else
    @php
        // Zoomed in well past the standalone board's 1.8px/min — at this
        // scale the 1-hour-before/3-hour-after window roughly fills this
        // panel's own width on a typical wall-display screen; exactly how
        // many hours end up visible past that still depends on how wide the
        // panel actually renders, same as any other fixed-scale timeline.
        $pxPerMinute = 4;
        $totalWidth = max($timelineEnd - $dayStart, 1) * $pxPerMinute + 24;
        $labelWidth = 110;
        $polaWidth = 56;
        $sidebarWidth = $labelWidth + $polaWidth;
        $earlyWindowMinutes = 60;
    @endphp
    <div class="relative h-full">
        {{-- Fixed "now" line — never moves. Sits $earlyWindowMinutes worth of
             px in from the sidebar, so there's always about an hour of
             already-past content visible to its left. --}}
        <div class="absolute top-0 bottom-0 z-30 pointer-events-none"
             style="left: {{ $sidebarWidth + $earlyWindowMinutes * $pxPerMinute }}px; width: 2px; background: #22d3ee;"></div>

        <div id="kesei-production-scroll" class="andon-scroll h-full overflow-auto bg-black"
             data-day-start="{{ $dayStart }}" data-px="{{ $pxPerMinute }}" data-now="{{ $nowMinute }}"
             data-total="{{ $timelineEnd }}" data-early-window="{{ $earlyWindowMinutes }}">
            <div class="h-full flex flex-col" style="width: {{ $sidebarWidth + $totalWidth }}px;">

                {{-- Time axis header --}}
                <div class="shrink-0 flex sticky top-0 z-20 bg-black border-b-2 border-white">
                    <div class="sticky left-0 z-40 bg-black shrink-0 flex" style="width: {{ $sidebarWidth }}px;">
                        <div class="shrink-0" style="width: {{ $labelWidth }}px;"></div>
                        <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-[9px] uppercase tracking-wide text-slate-400 font-semibold" style="width: {{ $polaWidth }}px;">
                            {{ __('Pola') }}
                        </div>
                    </div>
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
                        <div class="grid-line-dark z-0" style="left: {{ $sidebarWidth + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                    @endfor

                    @foreach ($keseiRows as $row)
                        @php
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
                            <div class="sticky left-0 z-20 flex shrink-0" style="width: {{ $sidebarWidth }}px;">
                                <div class="flex flex-col justify-center px-3 shrink-0 border-r border-white/10"
                                     style="width: {{ $labelWidth }}px; background-color: {{ $labelBg }};">
                                    <span class="font-bold text-white text-xs truncate">{{ $row['label'] }}</span>
                                    @if (count($row['sources']) > 1 || ($row['sources'][0] ?? null) !== $row['label'])
                                        <span class="text-[9px] text-slate-400 truncate" title="{{ implode(', ', $row['sources']) }}">&sum; {{ implode(', ', $row['sources']) }}</span>
                                    @endif
                                </div>
                                <div class="flex items-center justify-center shrink-0 border-r border-white/10"
                                     style="width: {{ $polaWidth }}px; background-color: {{ $labelBg }};"
                                     title="{{ __('Pola') }} {{ $row['pola'] !== '' ? $row['pola'] : '—' }}">
                                    <span class="text-xs font-extrabold tracking-wide">
                                        @forelse (str_split($row['pola']) as $letter)
                                            <span style="color: {{ $letter === $currentPattern ? '#4ade80' : '#ffffff' }};">{{ $letter }}</span>
                                        @empty
                                            <span class="text-slate-600">—</span>
                                        @endforelse
                                    </span>
                                </div>
                            </div>
                            <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                                @php
                                    $ck = collect($stockDecreaseEvents[$row['id']] ?? [])->sum('kanban');
                                @endphp
                                @foreach ($row['closing_markers'] as $marker)
                                    <div class="closing-time-marker absolute top-0 bottom-0 z-10"
                                         style="left: {{ max(0, ($marker['minute'] - $dayStart) * $pxPerMinute) }}px; border-left-color: {{ $row['pola_color'] }}; filter: drop-shadow(0 0 3px {{ $row['pola_color'] }});"
                                         title="{{ __('Closing time') }} {{ $marker['label'] }} — {{ $ck }} kanban berjalan">
                                        <span @if ($ck > 0) style="color: #4ade80; text-shadow: 0 0 4px rgba(74,222,128,0.6);" @endif
                                              class="absolute bottom-0.5 left-1 text-[9px] font-bold leading-none whitespace-nowrap {{ $ck > 0 ? 'text-green-700' : 'text-slate-500' }}">{{ $ck }}</span>
                                    </div>
                                @endforeach
                                @foreach ($stockDecreaseEvents[$row['id']] ?? [] as $event)
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
    </div>
@endif
