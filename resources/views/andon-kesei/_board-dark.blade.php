{{-- Dark-mode twin of _board.blade.php, used only by the standalone
     /andon-kesei and /andon-kesei-scan pages. --}}
<div class="h-full min-h-0 flex bg-black">
    <div id="kesei-panel-timeline" class="h-full min-w-0 border-r-2 border-white" style="flex: 3 1 0%;">
        @include('andon-kesei._timeline-dark')
    </div>
    <div class="h-full min-w-0 flex flex-col" style="flex: 1 1 0%;">
        <div id="kesei-panel-closing" class="min-h-0 shrink-0 border-b-2 border-white overflow-hidden" style="max-height: 45%;">
            @include('andon-kesei._closing-table-dark')
        </div>
        <div id="kesei-panel-stock" class="flex-1 min-h-0 overflow-hidden">
            @include('andon-kesei._stock-timeline-dark')
        </div>
    </div>
</div>
