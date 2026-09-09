<div class="h-full flex flex-col">
    <div class="shrink-0 flex items-center gap-2 px-3 py-1.5">
        <span class="text-xs font-bold text-slate-600 tracking-wide">{{ __('CLOSING TIME') }}</span>
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
                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-400 border-b border-slate-200">
                    <th class="py-1 pr-2 font-semibold">{{ __('Close') }}</th>
                    <th class="py-1 pr-2 font-semibold">{{ __('No Part') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Pattern') }}</th>
                    <th class="py-1 font-semibold text-center">{{ __('Qty Kbn') }}</th>
                </tr>
            </thead>
            <tbody class="text-slate-600">
                @forelse ($closingRows as $row)
                    <tr class="border-b border-slate-100">
                        <td class="py-1 pr-2 whitespace-nowrap">{{ $row['closing_label'] ?? '-' }}</td>
                        <td class="py-1 pr-2 truncate" title="{{ $row['label'] }}">{{ $row['label'] }}</td>
                        <td class="py-1 text-center truncate">{{ $row['planned_pattern'] ?? $currentPattern ?? '-' }}</td>
                        <td class="py-1 text-center whitespace-nowrap font-semibold text-slate-700">{{ $closingKanban[$row['id']] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="py-6 text-center text-slate-400">{{ __('Belum ada part yang closing.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
