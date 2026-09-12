{{-- Inner layout: the row/kolom/slot grid (75%) + the roller panel (25%),
     the same split proportions as Andon Kesei's timeline/closing-table. --}}
<div class="flex h-full min-h-0">
    <div id="lot-making-panel-grid" class="h-full min-w-0 overflow-hidden border-r-2 border-white" style="flex: 3 1 0%;">
        @include('andon-lot-making._grid')
    </div>
    <div id="lot-making-panel-roller" class="h-full min-w-0 overflow-hidden" style="flex: 1 1 0%;">
        @include('andon-lot-making._roller')
    </div>
</div>
