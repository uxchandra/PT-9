@if (empty($rows))
    <div class="h-full flex items-center justify-center text-center text-slate-500">
        {{ __('Belum ada data lot making.') }}
    </div>
@else
    <div class="andon-scroll h-full overflow-auto bg-black p-3">
        <div class="flex flex-col gap-3">
            @foreach ($rows as $band)
                <div class="flex items-stretch">
                    @foreach ($band['parts'] as $part)
                        @php
                            $slotCount = max(count($part['slots']), 1);
                            $cellWidth = 46;
                        @endphp
                        {{-- The "no" badge — one per part, same width whether
                             it's the first in the row band or a divider
                             before a later one. --}}
                        <div class="flex shrink-0 items-center justify-center border border-white text-sm font-bold text-white"
                             style="width: 34px; background-color: #0d6efd;">
                            {{ $part['no'] ?? '—' }}
                        </div>

                        <div class="shrink-0 border border-l-0 border-white bg-black"
                             style="width: {{ $slotCount * $cellWidth }}px;">
                            <div class="truncate border-b border-white bg-slate-800 px-2 py-1 text-center text-xs font-bold text-white">
                                {{ $part['part_no'] }}
                            </div>

                            @if ($part['slots'] !== [])
                                <div class="border-b border-white px-2 py-0.5 text-center text-[10px] font-semibold uppercase tracking-wide text-slate-300">
                                    {{ __('Lot Produksi') }}
                                </div>
                                <div class="flex border-b border-white">
                                    @foreach ($part['slots'] as $i => $value)
                                        <div class="flex flex-1 items-center justify-center border-white text-xs font-semibold text-white {{ $i === 0 ? '' : 'border-l' }}"
                                             style="min-width: {{ $cellWidth }}px; min-height: 28px;">
                                            {{ $value }}
                                        </div>
                                    @endforeach
                                </div>
                                {{-- Scan ticks — fills bottom-up per column, capped at that
                                     column's own capacity; green once a column is full. --}}
                                <div class="flex border-b border-white" style="height: 90px;">
                                    @foreach ($part['slots'] as $i => $capacity)
                                        @php
                                            $filled = $part['ticks'][$i] ?? 0;
                                            $pct = $capacity > 0 ? min(100, round($filled / $capacity * 100)) : 0;
                                            $isFull = $capacity > 0 && $filled >= $capacity;
                                        @endphp
                                        <div class="relative flex-1 border-white {{ $i === 0 ? '' : 'border-l' }}" style="min-width: {{ $cellWidth }}px;">
                                            <div class="absolute inset-x-0 bottom-0 {{ $isFull ? 'bg-green-400' : 'bg-sky-400' }}" style="height: {{ $pct }}%;"></div>
                                        </div>
                                    @endforeach
                                </div>
                                {{-- Total garis (scans) recorded so far per column — blank
                                     until a column has at least one. --}}
                                <div class="flex">
                                    @foreach ($part['slots'] as $i => $capacity)
                                        @php $filled = $part['ticks'][$i] ?? 0; @endphp
                                        <div class="flex flex-1 items-center justify-center border-white text-xs font-bold text-amber-400 {{ $i === 0 ? '' : 'border-l' }}"
                                             style="min-width: {{ $cellWidth }}px; min-height: 28px;">
                                            {{ $filled > 0 ? $filled : '' }}
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="px-2 py-4 text-center text-xs text-slate-400">
                                    {{ $part['lot_produksi'] ?? '-' }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
