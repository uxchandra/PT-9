<x-app-layout>
    <x-slot name="header">
        {{ __('Tambah Item Kelompok Pattern') }} — {{ $patternBoard->name }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-2xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('pattern-boards.group-items.store', $patternBoard) }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label :value="__('Pattern Board')" />
                    <p class="mt-1 text-sm font-medium text-gray-800">{{ $patternBoard->name }}</p>
                </div>

                <div>
                    <x-input-label for="part_id" :value="__('Part (P/N)')" />
                    <select id="part_id" name="part_id" required class="js-select2 mt-1 block w-full">
                        <option value="">{{ __('-- Pilih Part --') }}</option>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}" data-qty-kbn="{{ $part->qty_kbn }}" @selected(old('part_id') == $part->id)>{{ $part->part_no }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="shift" :value="__('Shift')" />
                    <select id="shift" name="shift" required
                            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                        @foreach (\App\Models\PatternGroupItem::SHIFT_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('shift', 1) == $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('shift')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="urutan" :value="__('Urutan (No)')" />
                    <x-text-input id="urutan" name="urutan" type="number" min="1" class="block w-full mt-1" :value="old('urutan')" required />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Menentukan posisi part ini dari atas ke bawah pada board, sekaligus urutan tampil di andon.') }}</p>
                    <x-input-error :messages="$errors->get('urutan')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="lot" :value="__('Lot')" />
                    <x-text-input id="lot" name="lot" type="number" min="0" class="block w-full mt-1" :value="old('lot')" required />
                    <x-input-error :messages="$errors->get('lot')" class="mt-2" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="loading_time" :value="__('Loading Time (menit)')" />
                        <x-text-input id="loading_time" name="loading_time" type="number" min="0" class="block w-full mt-1" :value="old('loading_time')" required />
                        <x-input-error :messages="$errors->get('loading_time')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="jumlah_proses" :value="__('Jumlah Proses')" />
                        <x-text-input id="jumlah_proses" name="jumlah_proses" type="number" min="1" class="block w-full mt-1" :value="old('jumlah_proses')" required />
                        <x-input-error :messages="$errors->get('jumlah_proses')" class="mt-2" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label :value="__('Total Kanban')" />
                        <div id="total-kanban-preview" class="mt-1 flex items-center h-10 px-3 rounded-lg border border-gray-200 bg-gray-50 text-sm font-medium text-gray-700">—</div>
                        <p class="mt-1 text-xs text-gray-500">{{ __('Otomatis: Lot ÷ Qty Kbn part (dibulatkan ke atas). Bukan input manual.') }}</p>
                    </div>
                    <div>
                        <x-input-label for="dandori" :value="__('Dandori (menit)')" />
                        <x-text-input id="dandori" name="dandori" type="number" min="0" class="block w-full mt-1" :value="old('dandori')" required />
                        <x-input-error :messages="$errors->get('dandori')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Simpan') }}</x-primary-button>
                    <a href="{{ route('pattern-boards.index', ['board' => $patternBoard->id]) }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const partSelect = document.getElementById('part_id');
            const lotInput = document.getElementById('lot');
            const preview = document.getElementById('total-kanban-preview');

            function updatePreview() {
                const option = partSelect.options[partSelect.selectedIndex];
                const qtyKbn = option ? parseFloat(option.dataset.qtyKbn) : NaN;
                const lot = parseFloat(lotInput.value);

                if (!option || !option.value || isNaN(qtyKbn) || qtyKbn <= 0 || isNaN(lot)) {
                    preview.textContent = '—';
                    return;
                }

                preview.textContent = Math.ceil(lot / qtyKbn);
            }

            // select2 dispatches a native 'change' on the underlying <select>
            // when a part is picked, so this listener needs no changes.
            partSelect.addEventListener('change', updatePreview);
            lotInput.addEventListener('input', updatePreview);
            updatePreview();
        })();
    </script>

    @push('styles')
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
        <style>
            .select2-container .select2-selection--single { height: 38px; border-color: #d1d5db; border-radius: 0.5rem; display: flex; align-items: center; }
            .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 1.5; color: #374151; padding-left: 0.75rem; }
            .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
            .select2-container--default.select2-container--focus .select2-selection--single,
            .select2-container--default.select2-container--open .select2-selection--single { border-color: #5d4037; box-shadow: 0 0 0 1px #5d4037; }
            .select2-dropdown { border-color: #d1d5db; }
            .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #5d4037; }
        </style>
    @endpush

    @push('scripts')
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                if (window.jQuery && jQuery.fn.select2) {
                    jQuery('.js-select2').select2({ width: '100%', placeholder: '{{ __('Cari part no...') }}', allowClear: true });
                }
            });
        </script>
    @endpush
</x-app-layout>
