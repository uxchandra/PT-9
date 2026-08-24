<div class="h-full flex flex-col">
    <div class="shrink-0 px-3 py-1.5 border-b border-slate-300 bg-slate-50">
        <span class="text-xs font-bold text-slate-600 tracking-wide">{{ __('TIMELINE STOK') }}</span>
        <p class="text-[11px] font-semibold truncate" x-text="koseiPart ? koseiPart.name : '{{ __('Pilih part di Kosei') }}'"
           :class="koseiPart ? 'text-brand-700' : 'text-slate-400'"></p>
    </div>
    <div class="andon-scroll flex-1 min-h-0 overflow-y-auto overflow-x-hidden">
        <table class="w-full text-xs border-collapse">
            <thead class="sticky top-0 z-10 bg-slate-50">
                <tr class="text-left text-[10px] uppercase tracking-wide text-slate-500">
                    <th class="px-2 py-1.5 border-b border-slate-200 font-semibold">{{ __('Waktu') }}</th>
                    <th class="px-2 py-1.5 border-b border-slate-200 font-semibold text-right">{{ __('Stok') }}</th>
                </tr>
            </thead>
            <tbody>
                <template x-if="!koseiPart">
                    <tr>
                        <td colspan="2" class="px-2 py-4 text-center text-slate-400">{{ __('Pilih part di Kosei') }}</td>
                    </tr>
                </template>
                <template x-if="koseiPart && stockHistoryLoading">
                    <tr>
                        <td colspan="2" class="px-2 py-4 text-center text-slate-400">{{ __('Memuat...') }}</td>
                    </tr>
                </template>
                <template x-if="koseiPart && !stockHistoryLoading && stockHistory.length === 0">
                    <tr>
                        <td colspan="2" class="px-2 py-4 text-center text-slate-400">{{ __('Belum ada histori stok terekam.') }}</td>
                    </tr>
                </template>
                <template x-for="(point, index) in stockHistory" :key="index">
                    <tr class="border-b border-slate-100" :class="point.under_min ? 'bg-red-50' : (index % 2 === 1 ? 'bg-slate-50/60' : '')">
                        <td class="px-2 py-1 text-slate-600" x-text="point.time"></td>
                        <td class="px-2 py-1 text-right font-semibold" :class="point.under_min ? 'text-red-600' : 'text-slate-700'" x-text="point.stock"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
