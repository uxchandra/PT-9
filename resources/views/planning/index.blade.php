<x-app-layout>
    <x-slot name="header">
        {{ __('Planning') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
            <p class="text-sm text-gray-500 mb-3">{{ __('Pilih pattern board untuk melihat/isi kanban planning & aktual.') }}</p>

            <div class="flex flex-wrap items-center gap-2">
                @forelse ($patternBoards as $board)
                    <a href="{{ route('planning.table', $board) }}"
                       class="px-4 py-2 rounded-lg text-sm font-semibold border bg-white text-gray-600 border-gray-200 hover:border-brand-300 hover:text-brand-700 transition">
                        {{ $board->name }}
                        <span class="ml-1 text-xs text-gray-400">({{ $board->patterns_count }})</span>
                    </a>
                @empty
                    <p class="text-gray-400 text-sm">{{ __('Belum ada pattern board.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
