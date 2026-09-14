{{-- The active Kanban queue: Open (not yet assigned to a machine) on top,
     In Progress (assigned, running) below — closed items drop off entirely
     (see LotMakingBoard::data). Each section keeps the standard chat-log
     trick (flex-col-reverse with the DOM in oldest-first order) so its
     newest entry hugs the bottom and scrollTop 0 always shows the newest. --}}
<div class="h-full flex flex-col bg-black">
    <div class="shrink-0 border-b-2 border-white px-3 py-2">
        <span class="text-sm font-bold tracking-wide text-white">{{ __('ANTRIAN KANBAN') }}</span>
    </div>

    <div class="flex flex-1 min-h-0 flex-col">
        <div class="flex flex-1 min-h-0 flex-col border-b-2 border-white">
            <div class="shrink-0 px-3 py-1.5 bg-white/5">
                <span class="text-xs font-bold tracking-wide text-blue-400">{{ __('OPEN') }}</span>
            </div>
            <div id="lot-making-roller-open" class="andon-scroll flex flex-1 min-h-0 flex-col-reverse overflow-y-auto px-3">
                @forelse ($openCycles as $cycle)
                    @include('andon-lot-making._roller-row', ['cycle' => $cycle, 'assignmentMachinesByPartNo' => $assignmentMachinesByPartNo])
                @empty
                    <div class="flex h-full items-center justify-center text-center text-sm text-slate-500">
                        {{ __('Tidak ada antrian.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <div class="flex flex-1 min-h-0 flex-col">
            <div class="shrink-0 px-3 py-1.5 bg-white/5">
                <span class="text-xs font-bold tracking-wide text-yellow-400">{{ __('IN PROGRESS') }}</span>
            </div>
            <div id="lot-making-roller-progress" class="andon-scroll flex flex-1 min-h-0 flex-col-reverse overflow-y-auto px-3">
                @forelse ($inProgressCycles as $cycle)
                    @include('andon-lot-making._roller-row', ['cycle' => $cycle])
                @empty
                    <div class="flex h-full items-center justify-center text-center text-sm text-slate-500">
                        {{ __('Belum ada yang sedang berjalan.') }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
