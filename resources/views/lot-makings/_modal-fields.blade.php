{{-- Shared by _create-modal.blade.php and _edit-modal.blade.php. $idSuffix
     keeps every field's id unique across however many of these modals sit in
     the DOM at once (one create + one per row). --}}
@php
    $lm = $lm ?? null;
@endphp

<div class="grid grid-cols-3 gap-4">
    <div>
        <x-input-label for="lm-no-{{ $idSuffix }}" :value="__('No')" />
        <x-text-input id="lm-no-{{ $idSuffix }}" name="no" type="number" min="0" class="block w-full mt-1"
                      :value="old('no', $lm?->no)" />
        <p class="mt-1 text-xs text-gray-400">{{ __('Posisi urutan (opsional)') }}</p>
        <x-input-error :messages="$errors->get('no')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="lm-row-{{ $idSuffix }}" :value="__('Row')" />
        <x-text-input id="lm-row-{{ $idSuffix }}" name="row" type="text" class="block w-full mt-1"
                      :value="old('row', $lm?->row)" />
        <x-input-error :messages="$errors->get('row')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="lm-kolom-{{ $idSuffix }}" :value="__('Kolom')" />
        <x-text-input id="lm-kolom-{{ $idSuffix }}" name="kolom" type="text" class="block w-full mt-1"
                      :value="old('kolom', $lm?->kolom)" />
        <x-input-error :messages="$errors->get('kolom')" class="mt-2" />
    </div>
</div>

<div class="mt-5">
    <x-input-label for="lm-part_id-{{ $idSuffix }}" :value="__('Part No')" />
    <select id="lm-part_id-{{ $idSuffix }}" name="part_id" required
            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
        <option value="">{{ __('— pilih part —') }}</option>
        @foreach ($parts as $part)
            <option value="{{ $part->id }}" @selected((int) old('part_id', $lm?->part_id) === $part->id)>{{ $part->part_no }}</option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
</div>

<div class="mt-5">
    <x-input-label for="lm-level-{{ $idSuffix }}" :value="__('Level (Kartu Scanner)')" />
    <select id="lm-level-{{ $idSuffix }}" name="level"
            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
        <option value="">{{ __('— tidak ditampilkan di scanner —') }}</option>
        @foreach (\App\Models\LotMaking::LEVELS as $value => $label)
            <option value="{{ $value }}" @selected(old('level', $lm?->level) === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-400">{{ __('Menentukan part ini muncul di kartu scanner mana (Finish Goods / Store 3).') }}</p>
    <x-input-error :messages="$errors->get('level')" class="mt-2" />
</div>

<div class="mt-5">
    <x-input-label for="lm-pulling_command-{{ $idSuffix }}" :value="__('Perintah Pulling')" />
    <x-text-input id="lm-pulling_command-{{ $idSuffix }}" name="pulling_command" type="number" min="0" class="block w-full mt-1"
                  :value="old('pulling_command', $lm?->pulling_command)" />
    <p class="mt-1 text-xs text-gray-400">{{ __('Target Finish Goods saat ini — otomatis lanjut mengikuti penurunan stok & scan setelah diisi. Boleh diubah kapan saja.') }}</p>
    <x-input-error :messages="$errors->get('pulling_command')" class="mt-2" />
</div>

<div class="grid grid-cols-2 gap-4 mt-5">
    <div>
        <x-input-label for="lm-lot_produksi-{{ $idSuffix }}" :value="__('Lot Produksi')" />
        <x-text-input id="lm-lot_produksi-{{ $idSuffix }}" name="lot_produksi" type="number" min="0" class="block w-full mt-1"
                      :value="old('lot_produksi', $lm?->lot_produksi)" />
        <x-input-error :messages="$errors->get('lot_produksi')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="lm-slot-{{ $idSuffix }}" :value="__('Slot')" />
        <x-text-input id="lm-slot-{{ $idSuffix }}" name="slot" type="number" min="1" class="block w-full mt-1"
                      :value="old('slot', $lm?->slot)" />
        <x-input-error :messages="$errors->get('slot')" class="mt-2" />
    </div>
</div>

<div class="grid grid-cols-3 gap-4 mt-5">
    <div>
        <x-input-label for="lm-loading_time-{{ $idSuffix }}" :value="__('Loading Time (menit)')" />
        <x-text-input id="lm-loading_time-{{ $idSuffix }}" name="loading_time" type="number" min="0" class="block w-full mt-1"
                      :value="old('loading_time', $lm?->loading_time)" />
        <x-input-error :messages="$errors->get('loading_time')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="lm-dandori-{{ $idSuffix }}" :value="__('Dandori (menit)')" />
        <x-text-input id="lm-dandori-{{ $idSuffix }}" name="dandori" type="number" min="0" class="block w-full mt-1"
                      :value="old('dandori', $lm?->dandori)" />
        <x-input-error :messages="$errors->get('dandori')" class="mt-2" />
    </div>
    <div>
        <x-input-label for="lm-jumlah_proses-{{ $idSuffix }}" :value="__('Jumlah Proses')" />
        <x-text-input id="lm-jumlah_proses-{{ $idSuffix }}" name="jumlah_proses" type="number" min="1" class="block w-full mt-1"
                      :value="old('jumlah_proses', $lm?->jumlah_proses)" />
        <x-input-error :messages="$errors->get('jumlah_proses')" class="mt-2" />
    </div>
</div>

@if ($lm && $lm->slot_fix !== null)
    <div class="flex gap-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 mt-5">
        <span>{{ __('Avg Slot') }}: <strong class="text-gray-800">{{ number_format($lm->avg_slot, 2) }}</strong></span>
        <span>{{ __('Slot Fix') }}: <strong class="text-gray-800">{{ $lm->slot_fix }}</strong></span>
        <span class="text-gray-400">{{ __('(dihitung otomatis dari Lot Produksi ÷ Slot)') }}</span>
    </div>
@endif
