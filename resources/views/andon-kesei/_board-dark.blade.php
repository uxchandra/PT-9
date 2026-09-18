{{-- Dark-mode twin of _board.blade.php, used only by the standalone
     /andon-kesei and /andon-kesei-scan pages. The heijunka board has no
     closing-time/antrian story, so its right sidebar is dropped entirely
     and the timeline takes the full width. --}}
<div class="h-full min-h-0 flex bg-black">
    <div id="kesei-panel-timeline" class="h-full min-w-0 {{ ($isHeijunka ?? false) ? '' : 'border-r-2 border-white' }}" style="flex: {{ ($isHeijunka ?? false) ? '1 1 0%' : '3 1 0%' }};">
        @include('andon-kesei._timeline-dark')
    </div>
    @unless ($isHeijunka ?? false)
        <div class="h-full min-w-0 flex flex-col" style="flex: 1 1 0%;">
            <div id="kesei-panel-closing" class="min-h-0 shrink-0 border-b-2 border-white overflow-hidden" style="max-height: 45%;">
                @include('andon-kesei._closing-table-dark')
            </div>
            <div id="kesei-panel-antrian" class="flex-1 min-h-0 overflow-hidden">
                @include('andon-kesei._antrian-fix-volume-dark')
            </div>
        </div>
    @endunless
</div>
