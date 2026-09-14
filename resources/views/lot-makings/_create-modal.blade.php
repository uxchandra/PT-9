{{-- The only way to add a Lot Making row — no standalone create page.
     Re-opens itself with validation errors as long as the failed submit
     wasn't from an edit modal (those flag themselves via _lot_making_edit_id,
     see _edit-modal.blade.php). --}}
<x-modal name="lot-making-create" :show="$errors->any() && ! old('_lot_making_edit_id')" maxWidth="2xl">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Tambah Lot Making') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('lot-makings.store') }}">
            @csrf
            @include('lot-makings._modal-fields', ['lm' => null, 'parts' => $parts, 'idSuffix' => 'create'])

            <div class="flex items-center justify-end gap-3 pt-5">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Simpan') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
