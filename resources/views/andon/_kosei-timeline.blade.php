@if ($koseiParts->isEmpty())
    <div class="h-full flex items-center justify-center text-center text-slate-400">
        {{ __('Belum ada part yang di-assign untuk board ini.') }}
    </div>
@else
    {{-- +60min so the last hour tick gets a full column of its own instead of
         sitting flush against the right edge with no room after it. --}}
    @php $totalWidth = (max($timelineEnd - $dayStart, 1) + 60) * $pxPerMinute; @endphp
    <div class="andon-scroll h-full overflow-auto">
        <div class="h-full flex flex-col" style="width: {{ 110 + $totalWidth }}px;">

            {{-- Time axis header --}}
            <div class="shrink-0 flex sticky top-0 z-30 bg-slate-50 border-b border-slate-300">
                <div class="sticky left-0 z-40 bg-slate-50 shrink-0" style="width: 110px;"></div>
                <div class="relative shrink-0" style="width: {{ $totalWidth }}px; height: 28px;">
                    @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                        <div class="absolute top-0 h-full border-l border-slate-200 flex items-center text-[10px] text-slate-500 font-semibold pl-1"
                             style="left: {{ ($t - $dayStart) * $pxPerMinute }}px;">
                            {{ sprintf('%02d:00', floor($t / 60) % 24) }}
                        </div>
                    @endfor
                </div>
            </div>

            {{-- Part rows --}}
            <div class="relative flex-1">
                @for ($t = $dayStart; $t <= $timelineEnd; $t += 60)
                    <div class="grid-line z-0" style="left: {{ 110 + ($t - $dayStart) * $pxPerMinute }}px;"></div>
                @endfor

                @foreach ($koseiParts as $part)
                    <div class="flex border-b border-slate-200 transition-colors {{ $loop->even ? 'bg-slate-50/60' : 'bg-white' }}"
                         style="height: 48px;">
                        <div class="sticky left-0 z-20 flex items-center px-3 shrink-0"
                             style="width: 110px; background-color: {{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                            <span class="font-bold text-slate-700 text-xs truncate">{{ $part->part_no }}</span>
                        </div>
                        <div class="relative shrink-0" style="width: {{ $totalWidth }}px;">
                            @foreach ($stockDecreaseEvents[$part->id] ?? [] as $event)
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
