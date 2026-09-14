{{-- One modal per row in the Assignment Mesin table — @included once per
     $pattern from pattern-boards/_results.blade.php. Re-opens itself with
     validation errors via the hidden _pattern_edit_id field, since $errors
     is shared across every modal on the page. --}}
<x-modal :name="'pattern-edit-'.$pattern->id" :show="$errors->any() && (int) old('_pattern_edit_id') === $pattern->id" maxWidth="lg">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Edit Assignment Mesin') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('patterns.update', $pattern) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="_pattern_edit_id" value="{{ $pattern->id }}">

            <div>
                <x-input-label :value="__('Pattern Board')" />
                <p class="mt-1 text-sm font-medium text-gray-800">{{ $pattern->patternBoard->name }}</p>
            </div>

            <div>
                <x-input-label for="shift-{{ $pattern->id }}" :value="__('Shift')" />
                <select id="shift-{{ $pattern->id }}" name="shift" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    @foreach (\App\Models\Pattern::SHIFT_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected($pattern->shift == $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="machine_id-{{ $pattern->id }}" :value="__('Machine')" />
                <select id="machine_id-{{ $pattern->id }}" name="machine_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Machine --') }}</option>
                    @foreach ($editMachines as $machine)
                        <option value="{{ $machine->id }}" @selected($pattern->machine_id == $machine->id)>{{ $machine->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="part_id-{{ $pattern->id }}" :value="__('Part')" />
                <select id="part_id-{{ $pattern->id }}" name="part_id" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Part --') }}</option>
                    @foreach ($editAvailableParts as $part)
                        <option value="{{ $part->id }}" @selected($pattern->part_id == $part->id)>{{ $part->part_no }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="proses-{{ $pattern->id }}" :value="__('Proses ke-')" />
                <x-text-input id="proses-{{ $pattern->id }}" name="proses" type="number" min="1" class="block w-full mt-1" :value="$pattern->proses" required />
                <p class="mt-1 text-xs text-gray-500">{{ __('Part yang sama bisa punya nomor proses berbeda di mesin lain, mis. proses 1/3 di mesin ini, 2/3 di mesin lain.') }}</p>
            </div>

            @if ($errors->any() && (int) old('_pattern_edit_id') === $pattern->id)
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
