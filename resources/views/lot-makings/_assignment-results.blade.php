{{-- "Assignment Machine" — mirrors the Pattern page's own Assignment Mesin
     table (see pattern-boards/_results.blade.php), just for Lot Making
     parts and without a board/shift (see the migration for why). Table
     styling matches the Lot Making table above it (_results.blade.php) —
     same bordered/sticky-header look. --}}
<div id="assignment-machine" class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden mt-6">
    <div class="flex items-center justify-between p-6 border-b border-gray-100">
        <div>
            <h3 class="text-lg font-semibold text-gray-800">{{ __('Assignment Machine') }}</h3>
            <p class="mt-1 text-sm text-gray-500">{{ __('Part Lot Making apa diproses di mesin mana') }}</p>
        </div>
        <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'lot-making-assignment-create')"
                class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
            {{ __('Tambah Assignment') }}
        </button>
    </div>

    <p class="px-6 pt-4 text-sm text-gray-500">
        {{ $assignments->total() }} {{ __('data assignment') }}
    </p>

    <div class="mx-6 my-6 overflow-auto border-2 border-gray-300 rounded-lg" style="max-height: calc(100vh - 340px);">
        <table class="min-w-full text-xs whitespace-nowrap border-separate border-spacing-0">
            <thead>
                <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-gray-300">{{ __('Machine') }}</th>
                    <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Part') }}</th>
                    <th class="sticky top-0 z-10 bg-gray-50 px-4 py-3 border-b-2 border-l border-gray-300">{{ __('Proses') }}</th>
                    <th class="sticky top-0 right-0 z-20 bg-gray-50 px-4 py-3 border-b-2 border-l-2 border-gray-300 text-right">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr class="group hover:bg-gray-50">
                        <td class="px-4 py-2 border-b border-gray-200 font-semibold text-gray-800">{{ $assignment->machine?->name ?? '-' }}</td>
                        <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-700">{{ $assignment->part?->part_no ?? '-' }}</td>
                        <td class="px-4 py-2 border-b border-l border-gray-200 text-gray-600">
                            {{ $assignment->proses }}{{ $assignment->part?->lotMaking?->jumlah_proses ? '/'.$assignment->part->lotMaking->jumlah_proses : '' }}
                        </td>
                        <td class="sticky right-0 z-10 bg-white group-hover:bg-gray-50 px-4 py-2 border-b border-l-2 border-gray-300 text-right">
                            <button type="button" x-data="" x-on:click="$dispatch('open-modal', 'lot-making-assignment-edit-{{ $assignment->id }}')"
                                    class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</button>
                            <form action="{{ route('lot-making-assignments.destroy', $assignment) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus assignment ini?') }}');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada assignment machine.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($assignments->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">
            {{ $assignments->links() }}
        </div>
    @endif
</div>

@include('lot-makings._assignment-create-modal')

@foreach ($assignments as $assignment)
    @include('lot-makings._assignment-edit-modal', ['assignment' => $assignment, 'editParts' => $assignmentParts, 'editMachines' => $assignmentMachines])
@endforeach
