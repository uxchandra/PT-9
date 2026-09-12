@php
    $lm = $lm ?? null;
@endphp

@csrf

<div class="space-y-5">
    <div class="grid grid-cols-3 gap-4">
        <div>
            <x-input-label for="no" :value="__('No')" />
            <x-text-input id="no" name="no" type="number" min="0" class="block w-full mt-1"
                          :value="old('no', $lm?->no)" autofocus />
            <p class="mt-1 text-xs text-gray-400">{{ __('Posisi urutan (opsional)') }}</p>
            <x-input-error :messages="$errors->get('no')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="row" :value="__('Row')" />
            <x-text-input id="row" name="row" type="text" class="block w-full mt-1"
                          :value="old('row', $lm?->row)" />
            <x-input-error :messages="$errors->get('row')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="kolom" :value="__('Kolom')" />
            <x-text-input id="kolom" name="kolom" type="text" class="block w-full mt-1"
                          :value="old('kolom', $lm?->kolom)" />
            <x-input-error :messages="$errors->get('kolom')" class="mt-2" />
        </div>
    </div>

    <div>
        <x-input-label for="part_id" :value="__('Part No')" />
        <select id="part_id" name="part_id" class="js-select2 block w-full mt-1" required>
            <option value="">{{ __('— pilih part —') }}</option>
            @foreach ($parts as $part)
                <option value="{{ $part->id }}" @selected((int) old('part_id', $lm?->part_id) === $part->id)>{{ $part->part_no }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label for="lot_produksi" :value="__('Lot Produksi')" />
            <x-text-input id="lot_produksi" name="lot_produksi" type="number" min="0" class="block w-full mt-1"
                          :value="old('lot_produksi', $lm?->lot_produksi)" />
            <x-input-error :messages="$errors->get('lot_produksi')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="slot" :value="__('Slot')" />
            <x-text-input id="slot" name="slot" type="number" min="1" class="block w-full mt-1"
                          :value="old('slot', $lm?->slot)" />
            <x-input-error :messages="$errors->get('slot')" class="mt-2" />
        </div>
    </div>

    @if ($lm && $lm->slot_fix !== null)
        <div class="flex gap-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
            <span>{{ __('Avg Slot') }}: <strong class="text-gray-800">{{ number_format($lm->avg_slot, 2) }}</strong></span>
            <span>{{ __('Slot Fix') }}: <strong class="text-gray-800">{{ $lm->slot_fix }}</strong></span>
            <span class="text-gray-400">{{ __('(dihitung otomatis dari Lot Produksi ÷ Slot)') }}</span>
        </div>
    @endif

    <div class="flex items-center gap-3 pt-1">
        <x-primary-button>{{ __('Simpan') }}</x-primary-button>
        <a href="{{ route('lot-makings.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
    </div>
</div>

@push('styles')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .select2-container .select2-selection--single {
            height: 38px;
            border-color: #d1d5db;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            color: #374151;
            padding-left: 0.75rem;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #5d4037;
            box-shadow: 0 0 0 1px #5d4037;
        }
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
                jQuery('.js-select2').select2({
                    width: '100%',
                    placeholder: '{{ __('Cari part no...') }}',
                    allowClear: true,
                });
            }
        });
    </script>
@endpush
