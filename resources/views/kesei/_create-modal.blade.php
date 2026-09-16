{{-- The only way to add a part to Kesei — no inline row on the index page.
     Re-opens itself with validation errors on a failed submit. --}}
<x-modal name="kesei-create" :show="$errors->any()" maxWidth="lg">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Tambah Part Kesei') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('kesei.store') }}" class="flex flex-col gap-4">
            @csrf
            <div>
                <select name="part_id" class="js-select2 block w-full" required>
                    <option value="">{{ __('— pilih part —') }}</option>
                    @foreach ($availableParts as $part)
                        <option value="{{ $part->id }}" @selected((int) old('part_id') === $part->id)>{{ $part->part_no }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
            </div>
            <div>
                <input type="text" name="stock_source" value="{{ old('stock_source') }}"
                       placeholder="{{ __('SOS Code (opsional)') }}"
                       class="block w-full rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                <p class="mt-1 text-xs text-gray-400">{{ __('part no, pisah koma. Kosong = pakai part no sendiri') }}</p>
                <x-input-error :messages="$errors->get('stock_source')" class="mt-1" />
            </div>
            <div class="flex items-start gap-3">
                <div class="w-28">
                    <input type="text" name="level" value="{{ old('level') }}" placeholder="{{ __('Level') }}"
                           class="block w-full rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('Level (opsional)') }}</p>
                    <x-input-error :messages="$errors->get('level')" class="mt-1" />
                </div>
                <div class="flex-1">
                    <input type="time" name="closing_time" value="{{ old('closing_time') }}"
                           class="block w-full rounded-md border-gray-300 focus:border-brand-500 focus:ring-brand-500 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('Closing time') }}</p>
                    <x-input-error :messages="$errors->get('closing_time')" class="mt-1" />
                </div>
                <div class="pt-2">
                    <label class="inline-flex items-center gap-1.5 text-sm text-gray-700 pt-1.5">
                        <input type="hidden" name="closing_mode" value="end_of_day">
                        <input type="checkbox" name="closing_mode" value="pre_run"
                               @checked(old('closing_mode', 'pre_run') === 'pre_run')
                               class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        {{ __('Pre-run') }}
                    </label>
                    <p class="mt-1 text-xs text-gray-400">{{ __('Closing sebelum run') }}</p>
                    <x-input-error :messages="$errors->get('closing_mode')" class="mt-1" />
                </div>
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    @forelse ($patternBoards as $board)
                        <label class="inline-flex items-center gap-1 text-sm text-gray-700">
                            <input type="checkbox" name="pattern_board_ids[]" value="{{ $board->id }}"
                                   @checked(collect(old('pattern_board_ids', []))->map('intval')->contains($board->id))
                                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            {{ $board->name }}
                        </label>
                    @empty
                        <span class="text-xs text-gray-400">{{ __('Belum ada pattern board') }}</span>
                    @endforelse
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ __('Pattern (bisa lebih dari satu)') }}</p>
                <x-input-error :messages="$errors->get('pattern_board_ids')" class="mt-1" />
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" x-on:click="$dispatch('close')" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</button>
                <x-primary-button>{{ __('Tambah') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-modal>
