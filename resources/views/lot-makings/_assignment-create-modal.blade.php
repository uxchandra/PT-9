{{-- The only way to add an Assignment Machine row — no standalone create
     page. Re-opens itself with validation errors as long as the failed
     submit wasn't from an edit modal (those flag themselves via
     _lot_making_assignment_edit_id, see _assignment-edit-modal.blade.php). --}}
<x-modal name="lot-making-assignment-create" :show="$errors->any() && ! old('_lot_making_assignment_edit_id')" maxWidth="lg">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Tambah Assignment Machine') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('lot-making-assignments.store') }}" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="lma-create-part_id" :value="__('Part')" />
                <select id="lma-create-part_id" name="part_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Part --') }}</option>
                    @foreach ($assignmentParts as $part)
                        <option value="{{ $part->id }}" @selected((int) old('part_id') === $part->id)>{{ $part->part_no }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="lma-create-machine_id" :value="__('Machine')" />
                <select id="lma-create-machine_id" name="machine_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Machine --') }}</option>
                    @foreach ($assignmentMachines as $machine)
                        <option value="{{ $machine->id }}" @selected((int) old('machine_id') === $machine->id)>{{ $machine->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('machine_id')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="lma-create-proses" :value="__('Proses ke-')" />
                <x-text-input id="lma-create-proses" name="proses" type="number" min="1" class="block w-full mt-1" :value="old('proses')" required />
                <x-input-error :messages="$errors->get('proses')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Simpan') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
