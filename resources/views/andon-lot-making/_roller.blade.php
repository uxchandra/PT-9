{{-- Completed lot cycles, oldest at top — newest lands at the bottom, where
     the panel auto-scrolls to (see show.blade.php). --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 border-b-2 border-white px-3 py-2">
        <span class="text-sm font-bold tracking-wide text-white">{{ __('PLANNING') }}</span>
    </div>
    <div id="lot-making-roller" class="andon-scroll flex-1 min-h-0 overflow-y-auto px-3 py-2">
        @forelse ($cycles as $cycle)
            <div class="mb-2 border border-slate-600 bg-slate-900 px-3 py-2">
                <p class="text-sm font-bold text-white">
                    {{ $cycle->part_no }}
                    <span class="font-normal text-slate-300">{{ __('Lot') }} {{ $cycle->lot_produksi }}</span>
                </p>
                <p class="text-xs text-slate-400">{{ __('Create') }} {{ $cycle->completed_at->format('d/m/Y H:i') }}</p>
            </div>
        @empty
            <div class="flex h-full items-center justify-center text-center text-sm text-slate-500">
                {{ __('Belum ada siklus produksi yang selesai.') }}
            </div>
        @endforelse
    </div>
</div>
