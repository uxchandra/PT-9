<div class="space-y-6">
    {{-- Kelompok Pattern --}}
    <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
        <div class="flex items-center justify-between p-6 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">{{ __('Kelompok Pattern') }}</h3>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Geser baris (⠿) untuk mengatur urutan — urutan ini menentukan susunan blok di board Andon.') }}
                    <span id="kp-reorder-status" class="ml-1 text-xs font-medium"></span>
                </p>
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
                        <th class="px-3 py-3 w-8"></th>
                        <th class="px-6 py-3">{{ __('No') }}</th>
                        <th class="px-6 py-3">{{ __('P/N') }}</th>
                        <th class="px-6 py-3">{{ __('Shift') }}</th>
                        <th class="px-6 py-3">{{ __('Lot') }}</th>
                        <th class="px-6 py-3">{{ __('Loading Time') }}</th>
                        <th class="px-6 py-3">{{ __('Jumlah Proses') }}</th>
                        <th class="px-6 py-3">{{ __('Total Kanban') }}</th>
                        <th class="px-6 py-3">{{ __('Dandori') }}</th>
                        <th class="px-6 py-3 w-32 text-right">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody id="kp-sortable" class="divide-y divide-gray-100"
                       data-reorder-url="{{ route('pattern-boards.group-items.reorder', $selectedBoard) }}"
                       data-locked="{{ $search !== '' ? '1' : '' }}">
                    @forelse ($groupItems as $item)
                        <tr class="hover:bg-gray-50" data-id="{{ $item->id }}">
                            <td class="kp-drag-handle px-3 py-3 text-center text-gray-300 hover:text-gray-500 cursor-grab select-none"
                                title="{{ __('Geser untuk mengatur urutan') }}">⠿</td>
                            <td class="kp-urutan-cell px-6 py-3 text-gray-600">{{ $item->urutan }}</td>
                            <td class="px-6 py-3 text-gray-800 font-medium">{{ $item->part->part_no }}</td>
                            <td class="px-6 py-3 text-gray-600">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold
                                             {{ $item->shift === 2 ? 'bg-indigo-50 text-indigo-700' : 'bg-amber-50 text-amber-700' }}">
                                    {{ __('Shift') }} {{ $item->shift }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-gray-600">{{ $item->lot }}</td>
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
                            <td colspan="10" class="px-6 py-8 text-center text-gray-400">
                                {{ $search !== '' ? __('Tidak ada item yang cocok dengan pencarian.') : __('Belum ada item kelompok pattern.') }}
                            </td>
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
                        <th class="px-6 py-3">{{ __('Shift') }}</th>
                        <th class="px-6 py-3">{{ __('Proses') }}</th>
                        <th class="px-6 py-3 w-32 text-right">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($patterns as $pattern)
                        @php $groupItem = $groupItemsByPart->get($pattern->part_id.'-'.$pattern->shift); @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-800 font-medium">{{ $pattern->machine->name }}</td>
                            <td class="px-6 py-3 text-gray-600">{{ $pattern->part->part_no }}</td>
                            <td class="px-6 py-3 text-gray-600">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold
                                             {{ $pattern->shift === 2 ? 'bg-indigo-50 text-indigo-700' : 'bg-amber-50 text-amber-700' }}">
                                    {{ __('Shift') }} {{ $pattern->shift }}
                                </span>
                            </td>
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
                            <td colspan="5" class="px-6 py-8 text-center text-gray-400">
                                {{ $search !== '' ? __('Tidak ada assignment yang cocok dengan pencarian.') : __('Belum ada assignment mesin.') }}
                            </td>
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
</div>
