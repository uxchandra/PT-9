{{-- Inner layout of the Kesei board: timeline + (closing table / stock table).
     Shared by the standalone page and the KESEI card on the Andon board. --}}
<div class="h-full min-h-0 flex gap-3">
    <div id="kesei-panel-timeline" class="h-full min-w-0" style="flex: 3 1 0%;">
        @include('andon-kesei._timeline')
    </div>
    <div class="h-full min-w-0 flex flex-col gap-2" style="flex: 1 1 0%;">
        <div id="kesei-panel-closing" class="min-h-0 shrink-0 rounded-lg border border-slate-300 overflow-hidden" style="max-height: 45%;">
            @include('andon-kesei._closing-table')
        </div>
        <div id="kesei-panel-stock" class="flex-1 min-h-0 rounded-lg border border-slate-300 overflow-hidden">
            @include('andon-kesei._stock-timeline')
        </div>
    </div>
</div>
