<x-modal name="lot-making-import" :show="$errors->any()" maxWidth="2xl">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Import Lot Making') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="mb-6">
            <a href="{{ route('lot-makings.import.template') }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-blue-100 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v12m0 0l-4-4m4 4l4-4M4 20h16"/>
                </svg>
                {{ __('Unduh template Excel') }}
            </a>
        </div>

        <form method="POST" action="{{ route('lot-makings.import.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            <div>
                <x-input-label for="lm-import-file" :value="__('File Excel/CSV')" />
                <input id="lm-import-file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                       class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-800 file:text-white hover:file:bg-brand-900" />
                <x-input-error :messages="$errors->get('file')" class="mt-2" />
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Import') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
