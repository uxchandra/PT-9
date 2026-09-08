@php
    $lm = $lm ?? null;
    $numericFields = [
        'qty_kanban' => 'Qty / Kanban',
        'lot' => 'Lot',
        'loading_time' => 'Loading Time',
        'dandori' => 'Dandori',
        'lot_produksi' => 'Lot Produksi',
        'safety_stock' => 'Safety Stock',
        'total_kanban_edar' => 'Total Kanban Edar',
        'kapasitas_rak' => 'Kapasitas Rak',
    ];
@endphp

@csrf

<div class="space-y-5">
    <div>
        <x-input-label for="assy_part_code" :value="__('Assy Part Code')" />
        <x-text-input id="assy_part_code" name="assy_part_code" type="text" class="block w-full mt-1"
                      :value="old('assy_part_code', $lm?->assy_part_code)" required autofocus />
        <x-input-error :messages="$errors->get('assy_part_code')" class="mt-2" />
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

    <div>
        <x-input-label for="next_process" :value="__('Next Process')" />
        <x-text-input id="next_process" name="next_process" type="text" class="block w-full mt-1"
                      :value="old('next_process', $lm?->next_process)" />
        <x-input-error :messages="$errors->get('next_process')" class="mt-2" />
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @foreach ($numericFields as $field => $label)
            <div>
                <x-input-label :for="$field" :value="__($label)" />
                <x-text-input :id="$field" :name="$field" type="number" min="0" class="block w-full mt-1"
                              :value="old($field, $lm?->{$field})" />
                <x-input-error :messages="$errors->get($field)" class="mt-2" />
            </div>
        @endforeach
    </div>

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
