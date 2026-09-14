{{-- One modal per row in the Lot Making table — @included once per
     $lotMaking from lot-makings/_results.blade.php. Re-opens itself with
     validation errors via the hidden _lot_making_edit_id field, since
     $errors is shared across every modal on the page. --}}
<x-modal :name="'lot-making-edit-'.$lotMaking->id" :show="$errors->any() && (int) old('_lot_making_edit_id') === $lotMaking->id" maxWidth="2xl">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Edit Lot Making') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('lot-makings.update', $lotMaking) }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_lot_making_edit_id" value="{{ $lotMaking->id }}">
            @include('lot-makings._modal-fields', ['lm' => $lotMaking, 'parts' => $parts, 'idSuffix' => 'edit-'.$lotMaking->id])

            <div class="flex items-center justify-end gap-3 pt-5">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Simpan') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
