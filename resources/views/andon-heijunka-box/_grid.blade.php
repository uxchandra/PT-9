{{-- Heijunka grid — a fixed-width cell grid (not a proportional clock),
     matching the source spreadsheet's own uniform-column layout AND its own
     grouped structure: one shared time header, then per cycle_issue group a
     "Cyc-N" divider, a "Random Number" row, the group's part rows, and a
     "Sub Total Kbn" row — same as the sheet itself, plus a grand-total
     footer that stays pinned to the bottom of the viewport. See
     HeijunkaBoxBoard::cells()/data() for the cell sequence and the
     grouping/firing mechanism. --}}
@php
    $labelWidth = 150;
    $slotWidth = 44;
    $restWidth = 34;
    // The long break between shift 1 (ends 15:45) and shift 2 (starts
    // 20:05) gets a visibly wider column than a normal rest — it's a
    // different kind of gap (between shifts, not a mid-shift break).
    $shiftGapWidth = 120;

    $cellWidth = fn (array $cell) => match ($cell['type']) {
        'slot' => $slotWidth,
        'gap' => $shiftGapWidth,
        default => $restWidth,
    };

    // Plain grey, like the sheet's own grey rest columns — lighter for a
    // mid-shift rest, darker for the long gap between shifts.
    $restStyle = 'background-color: #475569;';
    $gapStyle = 'background-color: #334155;';
@endphp
@if (empty($groups) || collect($groups)->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada part di jadwal Heijunka.') }}
    </div>
@else
    <div class="andon-scroll h-full overflow-auto bg-black">
        @php
            $totalWidth = $labelWidth + collect($cells)->sum($cellWidth);

            // Progress bar: the pixel offset right after the current slot's
            // own column — ticks only ever fire at/after this line, never
            // behind it (see HeijunkaBoxBoard's "release ahead of the
            // progress bar" rule).
            //
            // While $now sits in a rest / shift-gap column (right after the
            // current slot), the bar creeps across that column by how far
            // through it we are, instead of freezing on its left edge.
            $progressLeft = null;
            $offset = $labelWidth;
            $afterCurrent = false;
            foreach ($cells as $cell) {
                if ($afterCurrent) {
                    if ($cell['type'] !== 'slot') {
                        $progressLeft = $offset + ($progressFraction ?? 0) * $cellWidth($cell);
                    }
                    break;
                }
                $offset += $cellWidth($cell);
                if ($cell['type'] === 'slot' && $cell['time'] === ($currentSlotTime ?? null)) {
                    $progressLeft = $offset;
                    $afterCurrent = true;
                }
            }
        @endphp
        <div class="flex flex-col relative" style="width: {{ $totalWidth }}px;" @if ($progressLeft !== null) data-progress-left="{{ $progressLeft }}" @endif>
            @if ($progressLeft !== null)
                <div class="absolute inset-y-0 pointer-events-none" style="z-index: 25; left: {{ round($progressLeft) }}px; width: 3px; background: #f43f5e; box-shadow: 0 0 10px #f43f5e, 0 0 3px #f43f5e;">
                    <div class="absolute top-0 -ml-3 w-8 text-center text-[8px] font-bold text-white bg-rose-600 rounded-sm px-0.5 py-px">{{ __('NOW') }}</div>
                </div>
            @endif

            {{-- Header: one column per cell, part_no/cycle sidebar pinned left. --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-black border-b-2 border-white">
                <div class="sticky left-0 z-40 bg-black shrink-0 border-r border-white/10" style="width: {{ $labelWidth }}px;"></div>
                @foreach ($cells as $cell)
                    @if ($cell['type'] === 'slot')
                        <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-[9px] text-slate-300 font-semibold {{ $cell['time'] === ($currentSlotTime ?? null) ? 'bg-cyan-900/60 text-cyan-300' : '' }}"
                             style="width: {{ $slotWidth }}px; height: 28px;">
                            {{ $cell['time'] }}
                        </div>
                    @elseif ($cell['type'] === 'gap')
                        <div class="shrink-0 border-l border-white/20" style="width: {{ $shiftGapWidth }}px; height: 28px; {{ $gapStyle }}" title="{{ __('Jeda antar Shift 1 & Shift 2') }}"></div>
                    @else
                        <div class="shrink-0 border-l border-white/20" style="width: {{ $restWidth }}px; height: 28px; {{ $restStyle }}" title="{{ __('Istirahat') }}"></div>
                    @endif
                @endforeach
            </div>

            @foreach ($groups as $group)
                @php
                    // "Cyc-N" is merged from its own column up to just before the
                    // next label (rest columns in between included) or the shift
                    // 1 → 2 gap, whichever comes first.
                    $segments = [];
                    $current = null;
                    foreach ($cells as $cell) {
                        if ($cell['type'] === 'gap') {
                            if ($current) { $segments[] = $current; $current = null; }
                            $segments[] = ['gap' => true, 'width' => $shiftGapWidth];
                            continue;
                        }
                        $label = $cell['type'] === 'slot' ? ($group['cycle_labels'][$cell['time']] ?? null) : null;
                        if ($label !== null) {
                            if ($current) { $segments[] = $current; }
                            $current = ['label' => $label, 'width' => 0];
                        }
                        $current ??= ['label' => null, 'width' => 0];
                        $current['width'] += $cellWidth($cell);
                    }
                    if ($current) { $segments[] = $current; }
                @endphp
                {{-- Group divider: the cycle_issue label + the sheet's own "Cyc-N" sub-cycle markers. --}}
                <div class="shrink-0 flex sticky top-[28px] z-20 bg-slate-950 border-b border-white/20" style="height: 32px;">
                    <div class="sticky left-0 z-30 bg-slate-950 shrink-0 px-3 flex items-center border-r border-white/10" style="width: {{ $labelWidth }}px;">
                        <span class="text-base font-extrabold text-amber-400 tracking-wide">{{ $group['cycle_issue'] }}</span>
                    </div>
                    @foreach ($segments as $segment)
                        @if ($segment['gap'] ?? false)
                            <div class="shrink-0 border-l border-white/20" style="width: {{ $segment['width'] }}px; height: 32px; {{ $gapStyle }}"></div>
                        @elseif ($segment['label'] !== null)
                            <div class="shrink-0 flex items-center justify-center border-l border-white/20 bg-amber-900/50" style="width: {{ $segment['width'] }}px; height: 32px;">
                                <span class="text-sm font-extrabold text-amber-300">{{ $segment['label'] }}</span>
                            </div>
                        @else
                            <div class="shrink-0 border-l border-white/10 bg-slate-950" style="width: {{ $segment['width'] }}px; height: 32px;"></div>
                        @endif
                    @endforeach
                </div>

                {{-- Random Number row — fixed per-slot numbers from the sheet, same for this group every day. --}}
                <div class="shrink-0 flex border-b border-white/10 bg-slate-900" style="height: 20px;">
                    <div class="sticky left-0 z-20 bg-slate-900 shrink-0 px-3 flex items-center border-r border-white/10" style="width: {{ $labelWidth }}px;">
                        <span class="text-[8px] text-slate-500 italic">{{ __('Random Number') }}</span>
                    </div>
                    @foreach ($cells as $cell)
                        @if ($cell['type'] === 'slot')
                            <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-[9px] text-slate-400" style="width: {{ $slotWidth }}px; height: 20px;">
                                {{ $group['random_numbers'][$cell['time']] ?? '' }}
                            </div>
                        @elseif ($cell['type'] === 'gap')
                            <div class="shrink-0 border-l border-white/20" style="width: {{ $shiftGapWidth }}px; height: 20px; {{ $gapStyle }}"></div>
                        @else
                            <div class="shrink-0 border-l border-white/20" style="width: {{ $restWidth }}px; height: 20px; {{ $restStyle }}"></div>
                        @endif
                    @endforeach
                </div>

                {{-- Part rows in this group. --}}
                @foreach ($group['rows'] as $row)
                    @php
                        $ticksByTime = collect($row['ticks'])->groupBy('time');
                        $rowBg = $loop->even ? 'bg-slate-800/70' : 'bg-slate-700/50';
                        $labelBg = $loop->even ? '#1e293b' : '#334155';
                    @endphp
                    <div class="flex border-b border-white/10 {{ $rowBg }}" style="height: 44px;">
                        <div class="sticky left-0 z-20 flex flex-col justify-center px-3 shrink-0 border-r border-white/10"
                             style="width: {{ $labelWidth }}px; background-color: {{ $labelBg }};">
                            <span class="font-bold text-white text-xs truncate">{{ $row['label'] }}</span>
                        </div>
                        @foreach ($cells as $cell)
                            @if ($cell['type'] === 'slot')
                                @php $ticksHere = $ticksByTime->get($cell['time'], collect()); @endphp
                                <div class="shrink-0 flex items-center justify-center gap-0.5 border-l border-white/10 {{ $cell['time'] === ($currentSlotTime ?? null) ? 'bg-cyan-900/20' : '' }}"
                                     style="width: {{ $slotWidth }}px; height: 44px;">
                                    @foreach ($ticksHere as $tick)
                                        @php
                                            $color = match ($tick['heijunka_status']) {
                                                'scanned' => '#3b82f6',
                                                'overdue' => '#ff3b3b',
                                                default => '#22c55e',
                                            };
                                            $titleSuffix = match ($tick['heijunka_status']) {
                                                'scanned' => ' — sudah discan',
                                                'overdue' => ' — belum discan >15 menit',
                                                default => '',
                                            };
                                        @endphp
                                        <span class="block w-1.5 h-6 rounded-sm" style="background-color: {{ $color }}; box-shadow: 0 0 3px {{ $color }};"
                                              title="{{ $cell['time'] }}{{ $titleSuffix }}"></span>
                                    @endforeach
                                </div>
                            @elseif ($cell['type'] === 'gap')
                                <div class="shrink-0 border-l border-white/20" style="width: {{ $shiftGapWidth }}px; height: 44px; {{ $gapStyle }}"></div>
                            @else
                                <div class="shrink-0 border-l border-white/20" style="width: {{ $restWidth }}px; height: 44px; {{ $restStyle }}"></div>
                            @endif
                        @endforeach
                    </div>
                @endforeach

                {{-- Sub Total Kbn row — the group's own footer, same as the sheet. --}}
                <div class="shrink-0 flex border-b-2 border-white/30 bg-slate-800" style="height: 28px;">
                    <div class="sticky left-0 z-20 shrink-0 px-3 flex items-center border-r border-white/10" style="width: {{ $labelWidth }}px; background-color: #1e293b;">
                        <span class="text-[11px] font-bold text-white">{{ __('SUB TOTAL KBN') }}</span>
                    </div>
                    @foreach ($cells as $cell)
                        @if ($cell['type'] === 'slot')
                            @php $total = $group['subtotals'][$cell['time']] ?? 0; @endphp
                            <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-xs font-extrabold {{ $total > 0 ? 'text-cyan-300' : 'text-transparent' }}"
                                 style="width: {{ $slotWidth }}px; height: 28px;">
                                {{ $total > 0 ? $total : '' }}
                            </div>
                        @elseif ($cell['type'] === 'gap')
                            <div class="shrink-0 border-l border-white/20" style="width: {{ $shiftGapWidth }}px; height: 28px; {{ $gapStyle }}"></div>
                        @else
                            <div class="shrink-0 border-l border-white/20" style="width: {{ $restWidth }}px; height: 28px; {{ $restStyle }}"></div>
                        @endif
                    @endforeach
                </div>
            @endforeach

            {{-- Grand total footer — pinned to the bottom of the viewport, doesn't scroll away. --}}
            <div class="shrink-0 flex sticky bottom-0 z-30 border-t-2 border-white" style="height: 30px; background-color: #15803d;">
                <div class="sticky left-0 z-40 shrink-0 px-3 flex items-center border-r border-white/20" style="width: {{ $labelWidth }}px; background-color: #15803d;">
                    <span class="text-[11px] font-bold text-white">{{ __('TOTAL KBN/Cycle Pulling') }}</span>
                </div>
                @foreach ($cells as $cell)
                    @if ($cell['type'] === 'slot')
                        @php $total = $totals[$cell['time']] ?? 0; @endphp
                        <div class="shrink-0 flex items-center justify-center border-l border-white/20 text-xs font-extrabold {{ $total > 0 ? 'text-white' : 'text-transparent' }}"
                             style="width: {{ $slotWidth }}px; height: 30px;">
                            {{ $total > 0 ? $total : '' }}
                        </div>
                    @elseif ($cell['type'] === 'gap')
                        <div class="shrink-0 border-l border-white/20" style="width: {{ $shiftGapWidth }}px; height: 30px;"></div>
                    @else
                        <div class="shrink-0 border-l border-white/20" style="width: {{ $restWidth }}px; height: 30px;"></div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif
