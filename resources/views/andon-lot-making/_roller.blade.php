{{-- Completed lot cycles. flex-col-reverse (with the DOM still in oldest-
     first order) is the standard chat-log trick: the newest entry (last in
     the DOM) renders at the bottom, the list hugs the bottom of the panel
     even when it's shorter than the panel, and — once it overflows —
     scrollTop 0 always shows the newest without any JS scroll-to-bottom
     hack. --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 border-b-2 border-white px-3 py-2">
        <span class="text-sm font-bold tracking-wide text-white">{{ __('PLANNING') }}</span>
    </div>
    <div id="lot-making-roller" class="andon-scroll flex flex-1 min-h-0 flex-col-reverse overflow-y-auto px-3">
        @forelse ($cycles as $cycle)
            <div class="border-t border-white py-2">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-bold text-white">{{ $cycle->part_no }}</span>
                    <span class="text-sm text-slate-300">{{ __('Lot') }} {{ $cycle->lot_produksi }}</span>
                </div>
                <p class="text-xs text-slate-400">{{ __('Create') }} {{ $cycle->completed_at->format('d/m/Y H:i') }}</p>
            </div>
        @empty
            <div class="flex h-full items-center justify-center text-center text-sm text-slate-500">
                {{ __('Belum ada siklus produksi yang selesai.') }}
            </div>
        @endforelse
    </div>
</div>
