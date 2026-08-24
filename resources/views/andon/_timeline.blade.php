@if (empty($rows))
    <div class="h-full flex items-center justify-center text-center text-slate-400">
        {{ __('Belum ada pattern yang di-assign ke mesin untuk board ini.') }}
    </div>
@else
    @php $totalWidth = max($timelineEnd - $dayStart, 1) * $pxPerMinute; @endphp
    <div class="andon-scroll h-full overflow-auto">
        <div class="h-full flex flex-col" style="width: {{ 110 + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-slate-50 border-b border-slate-300">
                <div class="sticky left-0 z-40 bg-slate-50 border-r border-slate-300 shrink-0" style="width: 110px;"></div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-slate-200 flex items-center text-[10px] text-slate-500 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ sprintf('%02d:00', floor($t / 60) % 24) }}
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Rows + rest bands + gridlines --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
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
                    <div class="flex border-b border-slate-200 {{ $loop->even ? 'bg-slate-50/60' : 'bg-white' }}" style="height: 48px;">
                        <div class="sticky left-0 z-20 flex items-center px-3 border-r border-slate-300 shrink-0"
                             style="width: 110px; background: inherit; background-color: {{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                            <span class="font-bold text-slate-700 text-xs truncate">{{ $row['machine']->name }}</span>
                        </div>
                        <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                            @foreach ($row['blocks'] as $block)
                                @continue($block['type'] === 'free')
                                @php
                                    $left = ($block['start'] - $dayStart) * $pxPerMinute;
                                    $width = max(($block['end'] - $block['start']) * $pxPerMinute, 2);
                                @endphp
                                <div class="absolute top-1.5 bottom-1.5 z-10 flex flex-col items-center justify-center px-1 overflow-hidden leading-tight
                                             {{ $block['type'] === 'dandori' ? 'block-dandori' : 'block-loading' }}"
                                     style="left: {{ $left }}px; width: {{ $width }}px;
                                            @if ($block['type'] === 'loading') background-color: {{ $partColors[$block['part_id']] ?? '#f59e0b' }}; @endif"
                                     title="{{ $block['type'] === 'dandori' ? 'Dandori: '.$block['label'].' menit' : $block['label'].(isset($block['kanban']) ? ' — Kanban: '.$block['kanban'] : '') }}">
                                    @if ($block['type'] !== 'dandori' && ($block['showLabel'] ?? true))
                                        <span class="text-[10px] font-bold text-white truncate w-full text-center">{{ $block['label'] }}</span>
                                        @isset($block['kanban'])
                                            <span class="text-[9px] font-medium text-white/90 truncate w-full text-center">KB {{ $block['kanban'] }}</span>
                                        @endisset
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
