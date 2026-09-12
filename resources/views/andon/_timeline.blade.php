@if (empty($rows))
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
    </div>
@else
    {{-- +60min so the last hour tick gets a full column of its own instead of
         sitting flush against the right edge with no room after it. --}}
    @php
        $totalWidth = (max($timelineEnd - $dayStart, 1) + 60) * $pxPerMinute;
        // Andon Planning needs extra row height for the "actual kanban" input
        // sitting flush under each loading block. dandori stretches down to
        // match, so the two form one unbroken shape (dandori | loading/actual).
        $isPlanning = $isPlanning ?? false;
        // Andon (the monitoring board) only ever renders the Planning card
        // read-only — no inputs, just whatever's saved. Andon Planning (the
        // dedicated page reachable from the sidebar) is where the actual
        // kanban and kanban-override inputs actually live, so it defaults to
        // editable unless told otherwise.
        $editable = $editable ?? $isPlanning;
        $blockTop = 6;
        $loadingHeight = 36;
        $actualHeight = 22;
        $stackHeight = $loadingHeight + $actualHeight;
        $rowHeight = $isPlanning ? ($blockTop * 2 + $stackHeight) : 48;
    @endphp
    <div class="andon-scroll h-full overflow-auto">
        <div class="h-full flex flex-col" style="width: {{ 110 + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-black border-b-2 border-white">
                <div class="sticky left-0 z-40 bg-black border-r-2 border-white shrink-0" style="width: 110px;"></div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-white/10 flex items-center text-[10px] text-slate-300 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ sprintf('%02d:00', floor($t / 60) % 24) }}
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Rows + rest bands + gridlines --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                    <div class="grid-line z-0" style="left: {{ 110 + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                @foreach ($restIntervals as $rest)
                    @php
                        $clipStart = max($rest['start'], $dayStart);
                        $clipEnd = min($rest['end'], $timelineEnd);
                    @endphp
                    @if ($clipEnd > $clipStart)
                        <div class="rest-band absolute top-0 bottom-0 z-0"
                             style="left: {{ 110 + ($clipStart - $dayStart) * $pxPerMinute }}px; width: {{ ($clipEnd - $clipStart) * $pxPerMinute }}px;"
                             title="{{ $rest['name'] }}"></div>
                    @endif
                @endforeach

                @foreach ($rows as $row)
                    <div class="flex border-b border-white/10 {{ $loop->even ? 'bg-white/5' : 'bg-black' }}" style="height: {{ $rowHeight }}px;">
                        <div class="sticky left-0 z-20 flex items-center px-3 border-r-2 border-white shrink-0"
                             style="width: 110px; background: inherit; background-color: {{ $loop->even ? '#0f0f0f' : '#000000' }};">
                            <span class="font-bold text-white text-xs truncate">{{ $row['machine']->name }}</span>
                        </div>
                        <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                            @php $packedCursor = null; $prevBlockEnd = null; @endphp
                            @foreach ($row['blocks'] as $block)
                                @continue($block['type'] === 'free')
                                @php
                                    $left = ($block['start'] - $dayStart) * $pxPerMinute;
                                    $width = max(($block['end'] - $block['start']) * $pxPerMinute, 2);
                                    // Not yet "released" from Kesei (closing time hasn't produced a
                                    // decrease event yet) — showing "KB 0" would read as a real plan
                                    // of zero, so the caption is left off entirely instead.
                                    $showKanban = isset($block['kanban']) && ! ($isPlanning && (int) $block['kanban'] === 0);
                                    $showActualInput = $isPlanning && $block['type'] === 'loading' && ($block['showLabel'] ?? true);

                                    // Aktual row packs blocks back-to-back only across a truly zero
                                    // real gap (dandori/loading placed cursor-continuously within the
                                    // same shift). Any real idle stretch before this block — end of
                                    // shift 1, the shift-change gap, etc. — is genuine downtime, so
                                    // the block re-anchors to its own real time instead of continuing
                                    // the pack total across it (otherwise shift 2 would get dragged
                                    // back to sit right after shift 1).
                                    if ($isPlanning) {
                                        $actualLeft = ($prevBlockEnd !== null && $block['start'] <= $prevBlockEnd) ? $packedCursor : $left;
                                        // Advance the pack cursor by however much is actually drawn
                                        // for this block's Aktual piece — dandori draws full width,
                                        // the loading actual-input only draws 75% of it, and a
                                        // continuation segment (mid-part, no label) draws nothing at
                                        // all — so the next block's Aktual piece always touches the
                                        // real right edge of what's on screen, with zero blank space
                                        // in between.
                                        $actualRenderedWidth = match (true) {
                                            $block['type'] === 'dandori' => $width,
                                            $showActualInput => $width * 0.75,
                                            default => 0,
                                        };
                                        $packedCursor = $actualLeft + $actualRenderedWidth;
                                        $prevBlockEnd = $block['end'];
                                    }
                                @endphp

                                @if ($isPlanning)
                                    {{-- Rencana piece keeps its true timeline position (including any
                                         idle gap before it). --}}
                                    @if ($block['type'] === 'dandori')
                                        <div class="absolute z-10 overflow-hidden block-dandori"
                                             style="left: {{ $left }}px; width: {{ $width }}px; top: {{ $blockTop }}px; height: {{ $loadingHeight }}px;"
                                             title="{{ 'Dandori (Rencana): '.$block['label'].' menit' }}"></div>
                                    @else
                                        <div class="absolute z-10 flex flex-col items-center justify-center px-1 overflow-hidden leading-tight block-loading"
                                             style="left: {{ $left }}px; width: {{ $width }}px; top: {{ $blockTop }}px; height: {{ $loadingHeight }}px; background-color: {{ $partColors[$block['part_id']] ?? '#f59e0b' }};"
                                             title="{{ $block['label'].($showKanban ? ' — Kanban: '.$block['kanban'] : '') }}">
                                            @if ($block['showLabel'] ?? true)
                                                <span class="text-[10px] font-bold text-white truncate w-full text-center">{{ $block['label'] }}</span>
                                                @if ($editable)
                                                    <div class="flex items-center justify-center gap-1">
                                                        <span class="text-[9px] font-medium text-white/90">KB</span>
                                                        <input type="number" min="0" inputmode="numeric"
                                                               class="andon-kanban-override-input w-9 text-[9px] text-center text-white placeholder-white/60 bg-white/10 border border-white/30 rounded-sm px-0.5 leading-tight focus:outline-none focus:ring-1 focus:ring-white"
                                                               value="{{ $block['kanban_override'] ?? '' }}"
                                                               placeholder="{{ $block['kanban'] ?? 0 }}"
                                                               data-pattern-id="{{ $block['pattern_id'] }}"
                                                               title="{{ __('Override manual kanban (kosongkan untuk pakai hitungan otomatis dari Kesei)') }}">
                                                    </div>
                                                @elseif ($showKanban)
                                                    <span class="text-[9px] font-medium text-white/90 truncate w-full text-center">KB {{ $block['kanban'] }}{{ ($block['kanban_is_override'] ?? false) ? '*' : '' }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Aktual piece: packed contiguously, no idle gap before it. --}}
                                    @if ($block['type'] === 'dandori')
                                        <div class="absolute z-10 overflow-hidden block-dandori"
                                             style="left: {{ $actualLeft }}px; width: {{ $width }}px; top: {{ $blockTop + $loadingHeight }}px; height: {{ $actualHeight }}px;"
                                             title="{{ 'Dandori (Aktual): '.$block['label'].' menit' }}"></div>
                                    @elseif ($showActualInput)
                                        @if ($editable)
                                            <input type="number" min="0" inputmode="numeric"
                                                   class="andon-actual-input absolute z-10 text-[10px] text-center text-white placeholder-white/70 border-0 px-1 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white"
                                                   style="left: {{ $actualLeft }}px; width: {{ $width * 0.75 }}px; top: {{ $blockTop + $loadingHeight }}px; height: {{ $actualHeight }}px; background-color: {{ $partColors[$block['part_id']] ?? '#f59e0b' }};"
                                                   placeholder="{{ __('Aktual') }}"
                                                   value="{{ $block['actual_kanban'] ?? '' }}"
                                                   data-pattern-id="{{ $block['pattern_id'] }}"
                                                   title="{{ __('Total aktual kanban yang diproduksi') }}">
                                        @else
                                            {{-- Andon is read-only monitoring — show whatever was saved
                                                 on Andon Planning, but not editable here. --}}
                                            <div class="andon-actual-value absolute z-10 flex items-center justify-center text-[10px] text-white overflow-hidden"
                                                 style="left: {{ $actualLeft }}px; width: {{ $width * 0.75 }}px; top: {{ $blockTop + $loadingHeight }}px; height: {{ $actualHeight }}px; background-color: {{ $partColors[$block['part_id']] ?? '#f59e0b' }};"
                                                 title="{{ __('Total aktual kanban yang diproduksi') }}">
                                                {{ $block['actual_kanban'] ?? '' }}
                                            </div>
                                        @endif
                                    @endif
                                @else
                                    <div class="absolute top-1.5 bottom-1.5 z-10 flex flex-col items-center justify-center px-1 overflow-hidden leading-tight
                                                 {{ $block['type'] === 'dandori' ? 'block-dandori' : 'block-loading' }}"
                                         style="left: {{ $left }}px; width: {{ $width }}px;
                                                @if ($block['type'] === 'loading') background-color: {{ $partColors[$block['part_id']] ?? '#f59e0b' }}; @endif"
                                         title="{{ $block['type'] === 'dandori' ? 'Dandori: '.$block['label'].' menit' : $block['label'].($showKanban ? ' — Kanban: '.$block['kanban'] : '') }}">
                                        @if ($block['type'] !== 'dandori' && ($block['showLabel'] ?? true))
                                            <span class="text-[10px] font-bold text-white truncate w-full text-center">{{ $block['label'] }}</span>
                                            @if ($showKanban)
                                                <span class="text-[9px] font-medium text-white/90 truncate w-full text-center">KB {{ $block['kanban'] }}</span>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
