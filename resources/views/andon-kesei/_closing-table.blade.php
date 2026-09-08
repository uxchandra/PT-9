<div class="h-full flex flex-col">
    <div class="shrink-0 px-3 py-1.5 border-b border-slate-300 bg-slate-50">
        <span class="text-xs font-bold text-slate-600 tracking-wide">{{ __('CLOSING TIME') }}</span>
    </div>

    <div class="andon-scroll flex-1 min-h-0 overflow-auto">
        <table class="w-full text-[11px] border-collapse">
            <thead class="sticky top-0 z-10">
                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-500 bg-slate-50">
                    <th class="px-2 py-1.5 border-b border-slate-300 font-semibold whitespace-nowrap">{{ __('No Part') }}</th>
                    <th class="px-2 py-1.5 border-b border-l border-slate-200 font-semibold whitespace-nowrap">{{ __('Pattern') }}</th>
                    <th class="px-2 py-1.5 border-b border-l border-slate-200 font-semibold text-right whitespace-nowrap">{{ __('Qty Kbn') }}</th>
                    <th class="px-2 py-1.5 border-b border-l border-slate-200 font-semibold text-right whitespace-nowrap">{{ __('Closing') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($keseiRows as $i => $row)
                    <tr class="border-b border-slate-100 {{ $i % 2 === 1 ? 'bg-slate-50/60' : '' }}">
                        <td class="px-2 py-1 font-semibold text-slate-700 whitespace-nowrap">{{ $row['label'] }}</td>
                        <td class="px-2 py-1 border-l border-slate-100 text-slate-600 whitespace-nowrap">{{ implode(', ', $row['patterns']) ?: '-' }}</td>
                        <td class="px-2 py-1 border-l border-slate-100 text-right text-slate-600 whitespace-nowrap">{{ $row['qty_kbn'] ?? '-' }}</td>
                        <td class="px-2 py-1 border-l border-slate-100 text-right font-semibold whitespace-nowrap {{ $row['closing_label'] ? 'text-green-700' : 'text-slate-300' }}">
                            {{ $row['closing_label'] ?? '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-2 py-4 text-center text-slate-400">{{ __('Belum ada part di menu Kesei.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
