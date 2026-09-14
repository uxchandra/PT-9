{{-- One modal per row in the Kelompok Pattern table — @included once per
     $item from pattern-boards/_results.blade.php. Re-opens itself with
     validation errors via the hidden _group_item_edit_id field, since
     $errors is shared across every modal on the page. --}}
<x-modal :name="'group-item-edit-'.$item->id" :show="$errors->any() && (int) old('_group_item_edit_id') === $item->id" maxWidth="2xl">
    <div class="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-semibold text-gray-800">{{ __('Edit Item Kelompok Pattern') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Tutup') }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('group-items.update', $item) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <input type="hidden" name="_group_item_edit_id" value="{{ $item->id }}">

            <div>
                <x-input-label :value="__('Pattern Board')" />
                <p class="mt-1 text-sm font-medium text-gray-800">{{ $item->patternBoard->name }}</p>
            </div>

            <div>
                <x-input-label for="gi-part_id-{{ $item->id }}" :value="__('Part (P/N)')" />
                <select id="gi-part_id-{{ $item->id }}" name="part_id" required
                        class="gi-part-select mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    <option value="">{{ __('-- Pilih Part --') }}</option>
                    @foreach ($editParts as $part)
                        <option value="{{ $part->id }}" data-qty-kbn="{{ $part->qty_kbn }}" @selected($item->part_id == $part->id)>{{ $part->part_no }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="gi-shift-{{ $item->id }}" :value="__('Shift')" />
                <select id="gi-shift-{{ $item->id }}" name="shift" required
                        class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                    @foreach (\App\Models\PatternGroupItem::SHIFT_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected($item->shift == $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="gi-urutan-{{ $item->id }}" :value="__('Urutan (No)')" />
                <x-text-input id="gi-urutan-{{ $item->id }}" name="urutan" type="number" min="1" class="block w-full mt-1" :value="$item->urutan" required />
                <p class="mt-1 text-xs text-gray-500">{{ __('Menentukan posisi part ini dari atas ke bawah pada board, sekaligus urutan tampil di andon.') }}</p>
            </div>

            <div>
                <x-input-label for="gi-lot-{{ $item->id }}" :value="__('Lot')" />
                <x-text-input id="gi-lot-{{ $item->id }}" name="lot" type="number" min="0" class="gi-lot-input block w-full mt-1" :value="$item->lot" required />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="gi-loading_time-{{ $item->id }}" :value="__('Loading Time (menit)')" />
                    <x-text-input id="gi-loading_time-{{ $item->id }}" name="loading_time" type="number" min="0" class="block w-full mt-1" :value="$item->loading_time" required />
                </div>
                <div>
                    <x-input-label for="gi-jumlah_proses-{{ $item->id }}" :value="__('Jumlah Proses')" />
                    <x-text-input id="gi-jumlah_proses-{{ $item->id }}" name="jumlah_proses" type="number" min="1" class="block w-full mt-1" :value="$item->jumlah_proses" required />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label :value="__('Total Kanban')" />
                    <div class="gi-total-kanban-preview mt-1 flex items-center h-10 px-3 rounded-lg border border-gray-200 bg-gray-50 text-sm font-medium text-gray-700">{{ $item->total_kanban }}</div>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Otomatis: Lot ÷ Qty Kbn part (dibulatkan ke atas). Bukan input manual.') }}</p>
                </div>
                <div>
                    <x-input-label for="gi-dandori-{{ $item->id }}" :value="__('Dandori (menit)')" />
                    <x-text-input id="gi-dandori-{{ $item->id }}" name="dandori" type="number" min="0" class="block w-full mt-1" :value="$item->dandori" required />
                </div>
            </div>

            @if ($errors->any() && (int) old('_group_item_edit_id') === $item->id)
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
