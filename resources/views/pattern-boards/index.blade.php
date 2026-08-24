<x-app-layout>
    <x-slot name="header">
        {{ __('Pattern') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8 space-y-6">

        {{-- Board switcher --}}
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($patternBoards as $board)
                        <a href="{{ route('pattern-boards.index', ['board' => $board->id]) }}"
                           class="px-4 py-2 rounded-lg text-sm font-semibold border transition
                                  {{ $selectedBoard?->id === $board->id
                                        ? 'bg-brand-800 text-white border-brand-800 shadow-sm'
                                        : 'bg-white text-gray-600 border-gray-200 hover:border-brand-300 hover:text-brand-700' }}">
                            {{ $board->name }}
                        </a>
                    @endforeach
                    <a href="{{ route('pattern-boards.create') }}"
                       class="px-4 py-2 rounded-lg text-sm font-semibold border border-dashed border-gray-300 text-gray-500 hover:border-brand-400 hover:text-brand-700 transition">
                        + {{ __('Board') }}
                    </a>
                </div>

                @if ($selectedBoard)
                    <div class="flex items-center gap-2">
                        <a href="{{ route('pattern-boards.import.create', $selectedBoard) }}"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg font-semibold text-sm text-gray-700 hover:bg-gray-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            {{ __('Import') }}
                        </a>
                        <a href="{{ route('andon.show', $selectedBoard) }}" target="_blank"
                           class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-800 rounded-lg font-semibold text-sm text-white hover:bg-gray-900 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            {{ __('Lihat Andon') }}
                        </a>
                        <a href="{{ route('pattern-boards.edit', $selectedBoard) }}" title="{{ __('Ganti Nama') }}"
                           class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50 hover:text-gray-800 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </a>
                        <form action="{{ route('pattern-boards.destroy', $selectedBoard) }}" method="POST" onsubmit="return confirm('{{ __('Hapus board ini beserta seluruh data terkait?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" title="{{ __('Hapus Board') }}"
                                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg border border-gray-300 text-red-500 hover:bg-red-50 hover:text-red-700 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (! $selectedBoard)
            <div class="bg-white border border-gray-100 shadow-sm rounded-2xl p-16 text-center text-gray-400">
                {{ __('Belum ada pattern board. Klik "+ Board" untuk membuat yang pertama.') }}
            </div>
        @else
            {{-- Assignment Mesin --}}
            <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b border-gray-100">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">{{ __('Assignment Mesin') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Part apa diproses di mesin mana') }}</p>
                    </div>
                    <a href="{{ route('pattern-boards.patterns.create', $selectedBoard) }}"
                       class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
                        {{ __('Tambah Assignment') }}
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-6 py-3">{{ __('Machine') }}</th>
                                <th class="px-6 py-3">{{ __('Part') }}</th>
                                <th class="px-6 py-3">{{ __('Proses') }}</th>
                                <th class="px-6 py-3 w-32 text-right">{{ __('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($patterns as $pattern)
                                @php $groupItem = $groupItemsByPart->get($pattern->part_id); @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-800 font-medium">{{ $pattern->machine->name }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $pattern->part->name }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $pattern->proses }}/{{ $groupItem?->jumlah_proses ?? '?' }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('patterns.edit', $pattern) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                                        <form action="{{ route('patterns.destroy', $pattern) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus assignment ini?') }}');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada assignment mesin.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($patterns->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">
                        {{ $patterns->links() }}
                    </div>
                @endif
            </div>

            {{-- Kelompok Pattern --}}
            <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
                <div class="flex items-center justify-between p-6 border-b border-gray-100">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">{{ __('Kelompok Pattern') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Urutan & beban proses per part') }}</p>
                    </div>
                    <a href="{{ route('pattern-boards.group-items.create', $selectedBoard) }}"
                       class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
                        {{ __('Tambah Item') }}
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <th class="px-6 py-3">{{ __('No') }}</th>
                                <th class="px-6 py-3">{{ __('P/N') }}</th>
                                <th class="px-6 py-3">{{ __('Loading Time') }}</th>
                                <th class="px-6 py-3">{{ __('Jumlah Proses') }}</th>
                                <th class="px-6 py-3">{{ __('Total Kanban') }}</th>
                                <th class="px-6 py-3">{{ __('Dandori') }}</th>
                                <th class="px-6 py-3 w-32 text-right">{{ __('Aksi') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($groupItems as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-gray-600">{{ $item->urutan }}</td>
                                    <td class="px-6 py-3 text-gray-800 font-medium">{{ $item->part->name }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $item->loading_time }} {{ __('menit') }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $item->jumlah_proses }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $item->total_kanban }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ $item->dandori }} {{ __('menit') }}</td>
                                    <td class="px-6 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('group-items.edit', $item) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                                        <form action="{{ route('group-items.destroy', $item) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus item ini?') }}');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada item kelompok pattern.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($groupItems->hasPages())
                    <div class="px-6 py-4 border-t border-gray-100">
                        {{ $groupItems->links() }}
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
