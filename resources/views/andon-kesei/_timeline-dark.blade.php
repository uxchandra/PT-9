{{-- Dark-mode twin of _timeline.blade.php, used only by the standalone
     /andon-kesei and /andon-kesei-scan pages — the light original still
     serves the KESEI card embedded in the pattern-driven /andon board. --}}
@if ($keseiRows->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada part di menu Kesei.') }}
    </div>
@else
    @php
        $totalWidth = max($timelineEnd - $dayStart, 1) * $pxPerMinute + 24;
        $labelWidth = 110;
        $polaWidth = 56;
        $sidebarWidth = $labelWidth + $polaWidth;
    @endphp
    <div class="h-full flex flex-col bg-black">

        {{-- Time axis header — a separate scroller from the rows below (kept
             in sync horizontally via JS, see show.blade.php's
             __keseiHeaderSync), instead of "position: sticky" inside the same
             scroller. This guarantees it can never scroll out of view no
             matter how tall the row list gets — the same guarantee the
             page's own title bar and legend row have, since it now sits
             structurally outside the vertically-scrolling rows container
             entirely, not just visually pinned within it. --}}
        <div id="kesei-timeline-header-scroll" class="shrink-0 overflow-x-hidden overflow-y-hidden bg-black border-b-2 border-white">
            <div class="flex" style="width: {{ $sidebarWidth + $totalWidth }}px;">
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
        </div>

        {{-- Part rows — the only element that actually scrolls vertically. --}}
        <div id="kesei-timeline-rows-scroll" class="andon-scroll flex-1 min-h-0 overflow-auto bg-black">
            <div class="relative" style="width: {{ $sidebarWidth + $totalWidth }}px; min-height: 100%;">
                @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                    <div class="grid-line-dark z-0" style="left: {{ $sidebarWidth + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                {{-- Moving "now" line — nudged forward every second by the page script. --}}
                @if ($nowMinute >= $dayStart && $nowMinute <= $timelineEnd)
                    <div id="kesei-now-line" class="absolute top-0 bottom-0 z-20 pointer-events-none"
                         data-day-start="{{ $dayStart }}" data-px="{{ $pxPerMinute }}" data-now="{{ $nowMinute }}" data-total="{{ $timelineEnd }}" data-sidebar="{{ $sidebarWidth }}"
                         style="left: {{ $sidebarWidth + ($nowMinute - $dayStart) * $pxPerMinute }}px; width: 2px; background: #22d3ee;"></div>
                @endif

                @foreach ($keseiRows as $row)
                    @php
                        // Active rows alternate two neutral slate shades — dark
                        // enough to stay out of the way of the red/green/blue
                        // tick colours drawn on top, instead of competing with
                        // them the way the old amber did; inactive rows stay
                        // flat black and dimmed.
                        $rowBg = $row['runs_today']
                            ? ($loop->even ? 'bg-slate-800/70' : 'bg-slate-700/50')
                            : 'bg-black opacity-40';
                        $labelBg = $row['runs_today']
                            ? ($loop->even ? '#1e293b' : '#334155')
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
                            {{-- Pola: every pattern this row belongs to, one letter at a
                                 time — whichever letter is today's running pattern lights
                                 up green so it's obvious at a glance which rows include it,
                                 the rest read in the row's own pola colour (see
                                 KeseiBoard::polaColor). --}}
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
                                // Live count of the red ticks currently on this row — resets to
                                // 0 the moment the (most recent) closing folds them away.
                                $ck = collect($stockDecreaseEvents[$row['id']] ?? [])->sum('kanban');
                            @endphp
                            {{-- A row can carry more than one closing time a day (e.g. 05:00
                                 and 15:00) — one marker per definition. Not part of the
                                 heijunka board's story, so left out there. --}}
                            @unless ($isHeijunka ?? false)
                            @foreach ($row['closing_markers'] as $marker)
                                {{-- Coloured per the row's own pola (see KeseiBoard::polaColor)
                                     instead of a flat green, so a marker also says which
                                     pattern group it belongs to at a glance. --}}
                                <div class="closing-time-marker absolute top-0 bottom-0 z-10"
                                     style="left: {{ max(0, ($marker['minute'] - $dayStart) * $pxPerMinute) }}px; border-left-color: {{ $row['pola_color'] }}; filter: drop-shadow(0 0 3px {{ $row['pola_color'] }});"
                                     title="{{ __('Closing time') }} {{ $marker['label'] }} — {{ $ck }} kanban berjalan">
                                    {{-- style before class: keeps the literal "text-green-700" class
                                         (some tests key off it) while actually rendering a brighter
                                         green that reads on black. --}}
                                    <span @if ($ck > 0) style="color: #4ade80; text-shadow: 0 0 4px rgba(74,222,128,0.6);" @endif
                                          class="absolute bottom-0.5 left-1 text-[9px] font-bold leading-none whitespace-nowrap {{ $ck > 0 ? 'text-green-700' : 'text-slate-500' }}">{{ $ck }}</span>
                                </div>
                            @endforeach
                            @endunless
                            {{-- Planning markers: one thin line per planned release
                                 (cycle time + order_per_cycle, paced by lt_per_kbn —
                                 see KeseiBoard::planningMarkers). Heijunka-only, the
                                 mirror image of the closing markers above. --}}
                            @if ($isHeijunka ?? false)
                            @foreach ($row['planning_markers'] ?? [] as $marker)
                                <div class="planning-marker absolute top-0 bottom-0 z-10 pointer-events-none"
                                     style="left: {{ max(0, ($marker['minute'] - $dayStart) * $pxPerMinute) }}px;"
                                     title="{{ __('Planning') }} {{ $marker['time'] }} — {{ __('rilis ke-') }}{{ $marker['sequence'] }} ({{ __('cycle') }} {{ $marker['cycle_time'] }})"></div>
                            @endforeach
                            @endif
                            @foreach ($stockDecreaseEvents[$row['id']] ?? [] as $event)
                                @php
                                    // Heijunka recolours its ticks by status
                                    // instead of the flat red every other
                                    // board uses — see KeseiBoard::
                                    // heijunkaVisualEvents().
                                    $tickColor = '#ff3b3b';
                                    $tickGlow = 'rgba(255,59,59,0.9)';
                                    $tickTitleSuffix = '';
                                    if ($isHeijunka ?? false) {
                                        $status = $event['heijunka_status'] ?? 'pending';
                                        if ($status === 'scanned') {
                                            $tickColor = '#3b82f6';
                                            $tickGlow = 'rgba(59,130,246,0.9)';
                                            $tickTitleSuffix = ' — sudah discan';
                                        } elseif ($status === 'overdue') {
                                            $tickColor = '#ff3b3b';
                                            $tickGlow = 'rgba(255,59,59,0.9)';
                                            $tickTitleSuffix = ' — belum discan >15 menit';
                                        } else {
                                            $tickColor = '#22c55e';
                                            $tickGlow = 'rgba(34,197,94,0.9)';
                                        }
                                    }
                                @endphp
                                {{-- The controller already drops ticks that folded at the last run-day
                                     closing; whatever is left here is the live pile and always shows. --}}
                                <div class="absolute top-1 bottom-0.5 z-10 flex flex-col items-center"
                                     style="left: {{ ($event['minute'] - $dayStart) * $pxPerMinute }}px;"
                                     title="{{ $event['time'] }} — stok turun {{ $event['kanban'] }} kanban ({{ $event['pcs'] }} pcs){{ $tickTitleSuffix }}">
                                    <div class="flex-1 flex items-end gap-px">
                                        @for ($i = 0; $i < $event['kanban']; $i++)
                                            <span class="block w-0.5 h-full rounded-sm" style="background-color: {{ $tickColor }}; box-shadow: 0 0 3px {{ $tickGlow }};"></span>
                                        @endfor
                                    </div>
                                    @unless ($isHeijunka ?? false)
                                        <span class="text-[9px] leading-none font-bold mt-0.5" style="color: #ff5252;">{{ $event['kanban'] }}</span>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Total per hour (Heijunka/Heikinka only) — a fixed footer, same
             structural trick as the time-axis header above (a separate
             scroller, horizontally synced to the rows via __keseiHeaderSync
             in show.blade.php) so it never scrolls out of view either. The
             normal Kesei Kanban/Scan boards never showed this. --}}
        @if ($isHeijunka ?? false)
        <div id="kesei-timeline-footer-scroll" class="shrink-0 overflow-x-hidden overflow-y-hidden bg-black border-t-2 border-white">
            <div class="flex" style="width: {{ $sidebarWidth + $totalWidth }}px;">
                <div class="sticky left-0 z-40 bg-black shrink-0 flex items-center px-3" style="width: {{ $sidebarWidth }}px;">
                    <span class="text-xs uppercase tracking-wide text-slate-400 font-bold">{{ __('Total') }}</span>
                </div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 34px;">
                    @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-white/10 flex items-center justify-center text-base font-bold"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px; width: {{ 60 * $pxPerMinute }}px;">
                            @if (($hourlyTotals[$t] ?? 0) > 0)
                                <span style="color: #facc15;">{{ $hourlyTotals[$t] }}</span>
                            @else
                                <span class="text-slate-600">0</span>
                            @endif
                        </div>
                    @endfor
                </div>
            </div>

                {{-- Actual per plan (Heijunka only): blue (already pulled) out of
                     every tick on the board that hour — blue + red + green. --}}
                <div class="flex border-t border-white/20" style="width: {{ $sidebarWidth + $totalWidth }}px;">
                    <div class="sticky left-0 z-40 bg-black shrink-0 flex items-center px-3" style="width: {{ $sidebarWidth }}px;">
                        <span class="text-xs uppercase tracking-wide text-slate-400 font-bold">{{ __('Actual / Plan') }}</span>
                    </div>
                    <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 34px;">
                        @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                            @php
                                $actual = $hourlyActualPlan[$t]['actual'] ?? 0;
                                $plan = $hourlyActualPlan[$t]['plan'] ?? 0;
                            @endphp
                            <div class="absolute top-0 h-full border-l border-white/10 flex items-center justify-center text-base font-bold tabular-nums"
                                 style="left: {{ ($t - $dayStart) * $pxPerMinute }}px; width: {{ 60 * $pxPerMinute }}px;">
                                @if ($plan > 0)
                                    <span style="color: {{ $actual >= $plan ? '#3b82f6' : '#facc15' }};">{{ $actual }}/{{ $plan }}</span>
                                @else
                                    <span class="text-slate-600">-</span>
                                @endif
                            </div>
                        @endfor
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif
