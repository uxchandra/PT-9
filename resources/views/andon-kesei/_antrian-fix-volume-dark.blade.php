{{-- Antrian (Fix Volume) — every still-Open Lot Making Planning proses
     step. Paired with Closing Time (Fix Time) above it: that table closes on
     a clock time, this one queues on completed kanban volume instead — see
     KeseiBoard::loadOpenLotMakingQueue(). --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 px-3 py-1.5 border-b-2 border-white">
        <span class="text-xs font-bold text-white tracking-wide">{{ __('ANTRIAN (FIX VOLUME)') }}</span>
    </div>

    <div class="andon-scroll flex-1 min-h-0 overflow-y-auto px-3 pb-2">
        <table class="w-full text-xs table-fixed">
            <colgroup>
                <col style="width: 70px;">
                <col>
                <col style="width: 48px;">
                <col style="width: 56px;">
            </colgroup>
            <thead>
                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400 border-b border-white/20">
                    <th class="py-1 pr-2 font-semibold">{{ __('Created') }}</th>
                    <th class="py-1 pr-2 font-semibold">{{ __('No Part') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Lot') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Machine') }}</th>
                </tr>
            </thead>
            <tbody class="text-slate-300">
                @forelse ($openLotMakingQueue as $row)
                    <tr class="border-b border-white/10">
                        <td class="py-1 pr-2 whitespace-nowrap">{{ $row['created_at'] }}</td>
                        <td class="py-1 pr-2 truncate" title="{{ $row['part_no'] }}">{{ $row['part_no'] }}</td>
                        <td class="py-1 text-center whitespace-nowrap">{{ $row['lot'] }}</td>
                        <td class="py-1 text-center truncate font-semibold text-white" title="{{ $row['machine'] ?? '-' }}">
                            {{ $row['machine'] ?? '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-slate-500">{{ __('Belum ada antrian.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
