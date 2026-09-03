@if ($koseiParts->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-400">
        {{ __('Belum ada part yang di-assign untuk board ini.') }}
    </div>
@else
    {{-- +60min so the last hour tick gets a full column of its own instead of
         sitting flush against the right edge with no room after it. --}}
    @php
        $totalWidth = (max($timelineEnd - $dayStart, 1) + 60) * $pxPerMinute;
        $labelW = 110;   // part number column
        $ctW = 54;       // "CT" (closing-time kanban) column
        $leftPad = $labelW + $ctW;
        $closingKanban = $closingKanban ?? [];
    @endphp
    <div class="andon-scroll h-full overflow-auto">
        <div class="h-full flex flex-col" style="width: {{ $leftPad + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-slate-50 border-b border-slate-300">
                <div class="sticky left-0 z-40 bg-slate-50 shrink-0" style="width: {{ $labelW }}px;"></div>
                <div class="sticky z-40 bg-slate-50 shrink-0 flex items-center justify-center border-l border-slate-200"
                     style="left: {{ $labelW }}px; width: {{ $ctW }}px;">
                    <span class="text-[10px] font-bold text-slate-500" title="{{ __('Akumulasi kanban sampai closing time') }}">CT</span>
                </div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-slate-200 flex items-center text-[10px] text-slate-500 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ sprintf('%02d:00', floor($t / 60) % 24) }}
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Part rows --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t < $timelineEnd; $t += 60)
                    <div class="grid-line z-0" style="left: {{ $leftPad + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                @foreach ($koseiParts as $part)
                    @php
                        $hasCt = array_key_exists($part->id, $closingKanban);
                        $ct = $closingKanban[$part->id] ?? null;
                        $rowBg = $loop->even ? '#f8fafc' : '#ffffff';
                    @endphp
                    <div class="flex border-b border-slate-200 transition-colors {{ $loop->even ? 'bg-slate-50/60' : 'bg-white' }}"
                         style="height: 48px;">
                        <div class="sticky left-0 z-20 flex items-center px-3 shrink-0"
                             style="width: {{ $labelW }}px; background-color: {{ $rowBg }};">
                            <span class="font-bold text-slate-700 text-xs truncate">{{ $part->part_no }}</span>
                        </div>
                        <div class="sticky z-20 flex items-center justify-center shrink-0 border-l border-slate-200"
                             style="left: {{ $labelW }}px; width: {{ $ctW }}px; background-color: {{ $rowBg }};"
                             title="{{ __('Akumulasi kanban sampai closing time') }}">
                            <span class="text-xs font-bold {{ $hasCt && $ct > 0 ? 'text-green-700' : 'text-slate-400' }}">{{ $hasCt ? $ct : '–' }}</span>
                        </div>
                        <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                            @php $realClosing = $closingTimeMarkers[$part->id][0]['real'] ?? null; @endphp
                            @foreach ($closingTimeMarkers[$part->id] ?? [] as $marker)
                                <div class="closing-time-marker absolute top-0 bottom-0 z-10{{ $marker['clamped'] ? ' closing-time-marker--pinned' : '' }}"
                                     style="left: {{ max(0, ($marker['minute'] - $dayStart) * $pxPerMinute) }}px;"
                                     title="{{ __('Closing time') }} {{ sprintf('%02d:%02d', intdiv($marker['real'], 60) % 24, (($marker['real'] % 60) + 60) % 60) }}{{ $marker['clamped'] ? ' ('.__('sebelum awal timeline — dipin ke tepi').')' : '' }} — {{ __('akumulasi kanban H-4 jam dari mulai produksi') }}">
                                    @if ($hasCt)
                                        <span class="absolute bottom-0.5 left-1 text-[9px] font-bold leading-none whitespace-nowrap {{ $ct > 0 ? 'text-green-700' : 'text-slate-400' }}"
                                              title="{{ __('Akumulasi kanban sampai closing time') }}: {{ $ct }}">{{ $ct }}</span>
                                    @endif
                                </div>
                            @endforeach
                            @foreach ($stockDecreaseEvents[$part->id] ?? [] as $event)
                                {{-- Ticks up to the closing time are already folded into the
                                     accumulated number on the green line — only later ones
                                     (next accumulation) still draw. --}}
                                @continue($realClosing !== null && $event['minute'] <= $realClosing)
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
