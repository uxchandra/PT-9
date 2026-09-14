{{-- One modal per row in the Assignment Machine table — @included once per
     $assignment from lot-makings/_assignment-results.blade.php. Re-opens
     itself with validation errors via the hidden _lot_making_assignment_edit_id
     field, since $errors is shared across every modal on the page. --}}
<x-modal :name="'lot-making-assignment-edit-'.$assignment->id" :show="$errors->any() && (int) old('_lot_making_assignment_edit_id') === $assignment->id" maxWidth="lg">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Edit Assignment Machine') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('lot-making-assignments.update', $assignment) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="_lot_making_assignment_edit_id" value="{{ $assignment->id }}">

            <div>
                <x-input-label for="lma-part_id-{{ $assignment->id }}" :value="__('Part')" />
                <select id="lma-part_id-{{ $assignment->id }}" name="part_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Part --') }}</option>
                    @foreach ($editParts as $part)
                        <option value="{{ $part->id }}" @selected($assignment->part_id == $part->id)>{{ $part->part_no }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="lma-machine_id-{{ $assignment->id }}" :value="__('Machine')" />
                <select id="lma-machine_id-{{ $assignment->id }}" name="machine_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Machine --') }}</option>
                    @foreach ($editMachines as $machine)
                        <option value="{{ $machine->id }}" @selected($assignment->machine_id == $machine->id)>{{ $machine->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="lma-proses-{{ $assignment->id }}" :value="__('Proses ke-')" />
                <x-text-input id="lma-proses-{{ $assignment->id }}" name="proses" type="number" min="1" class="block w-full mt-1" :value="$assignment->proses" required />
            </div>

            @if ($errors->any() && (int) old('_lot_making_assignment_edit_id') === $assignment->id)
                <div class="rounded-lg bg-red-50 border border-red-100 p-3 text-sm text-red-700">
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Simpan') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
