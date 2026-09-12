{{-- Dark-mode twin of _closing-table.blade.php — see _timeline-dark.blade.php. --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 px-3 py-1.5 border-b-2 border-white">
        <span class="text-xs font-bold text-white tracking-wide">{{ __('CLOSING TIME') }}</span>
    </div>

    <div class="andon-scroll flex-1 min-h-0 overflow-y-auto px-3 pb-2">
        <table class="w-full text-xs table-fixed">
            <colgroup>
                <col style="width: 48px;">
                <col>
                <col style="width: 60px;">
                <col style="width: 56px;">
            </colgroup>
            <thead>
                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400 border-b border-white/20">
                    <th class="py-1 pr-2 font-semibold">{{ __('Close') }}</th>
                    <th class="py-1 pr-2 font-semibold">{{ __('No Part') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Pattern') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Qty Kbn') }}</th>
                </tr>
            </thead>
            <tbody class="text-slate-300">
                @forelse ($closingRows as $row)
                    <tr class="border-b border-white/10">
                        <td class="py-1 pr-2 whitespace-nowrap">{{ $row['closing_label'] ?? '-' }}</td>
                        <td class="py-1 pr-2 truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</td>
                        <td class="py-1 text-center truncate">{{ $row['planned_pattern'] ?? $currentPattern ?? '-' }}</td>
                        <td class="py-1 text-center whitespace-nowrap font-semibold text-white">{{ $closingKanban[$row['id']] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-slate-500">{{ __('Belum ada part yang closing.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
