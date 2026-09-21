{{-- Heijunka Box grid — a fixed-width cell grid (not a proportional clock),
     matching the source spreadsheet's own uniform-column layout AND its own
     grouped structure: one shared time header, then per cycle_issue group a
     "Random Number" row, the group's part rows, and a "Sub Total Kbn" row —
     same as the sheet itself. See HeijunkaBoxBoard::cells()/data() for the
     cell sequence and the grouping/firing mechanism. --}}
@php
    $labelWidth = 150;
    $slotWidth = 44;
    $gapWidth = 22;
@endphp
@if (empty($groups) || collect($groups)->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada part di jadwal Heijunka Box.') }}
    </div>
@else
    <div class="andon-scroll h-full overflow-auto bg-black">
        @php
            $totalWidth = $labelWidth + collect($cells)->sum(fn ($c) => $c['type'] === 'slot' ? $slotWidth : $gapWidth);
        @endphp
        <div class="flex flex-col" style="width: {{ $totalWidth }}px;">
            {{-- Header: one column per cell, part_no/cycle sidebar pinned left. --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-black border-b-2 border-white">
                <div class="sticky left-0 z-40 bg-black shrink-0 border-r border-white/10" style="width: {{ $labelWidth }}px;"></div>
                @foreach ($cells as $cell)
                    @if ($cell['type'] === 'slot')
                        <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-[9px] text-slate-300 font-semibold {{ $cell['time'] === ($currentSlotTime ?? null) ? 'bg-cyan-900/60 text-cyan-300' : '' }}"
                             style="width: {{ $slotWidth }}px; height: 28px;">
                            {{ $cell['time'] }}
                        </div>
                    @else
                        <div class="shrink-0 border-l border-white/10 bg-slate-900" style="width: {{ $gapWidth }}px; height: 28px;" title="{{ $cell['type'] === 'gap' ? __('Jeda antar shift') : __('Istirahat') }}"></div>
                    @endif
                @endforeach
            </div>

            @foreach ($groups as $group)
                {{-- Group divider: the cycle_issue label, like the sheet's own block header. --}}
                <div class="shrink-0 flex sticky top-[28px] z-20 bg-slate-950 border-b border-white/20" style="height: 22px;">
                    <div class="sticky left-0 z-30 bg-slate-950 shrink-0 px-3 flex items-center border-r border-white/10" style="width: {{ $labelWidth }}px;">
                        <span class="text-[10px] font-bold text-amber-400 tracking-wide">{{ $group['cycle_issue'] }}</span>
                    </div>
                    <div class="flex-1 bg-slate-950"></div>
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
                        @else
                            <div class="shrink-0 border-l border-white/10 bg-slate-900" style="width: {{ $gapWidth }}px; height: 20px;"></div>
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
                            @else
                                <div class="shrink-0 border-l border-white/10 bg-slate-900/60" style="width: {{ $gapWidth }}px; height: 44px;"></div>
                            @endif
                        @endforeach
                    </div>
                @endforeach

                {{-- Sub Total Kbn row — the group's own footer, same as the sheet. --}}
                <div class="shrink-0 flex border-b-2 border-white/30 bg-slate-800" style="height: 26px;">
                    <div class="sticky left-0 z-20 bg-slate-800 shrink-0 px-3 flex items-center border-r border-white/10" style="width: {{ $labelWidth }}px;">
                        <span class="text-[10px] font-bold text-white">{{ __('SUB TOTAL KBN') }}</span>
                    </div>
                    @foreach ($cells as $cell)
                        @if ($cell['type'] === 'slot')
                            @php $total = $group['subtotals'][$cell['time']] ?? 0; @endphp
                            <div class="shrink-0 flex items-center justify-center border-l border-white/10 text-[10px] font-bold {{ $total > 0 ? 'text-cyan-300' : 'text-slate-600' }}"
                                 style="width: {{ $slotWidth }}px; height: 26px;">
                                {{ $total > 0 ? $total : '' }}
                            </div>
                        @else
                            <div class="shrink-0 border-l border-white/10 bg-slate-900" style="width: {{ $gapWidth }}px; height: 26px;"></div>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
