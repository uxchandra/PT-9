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

        <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
            <p class="font-semibold mb-1">{{ __('Format file (.xlsx, .xls, atau .csv)') }}</p>
            <p class="mb-2">{{ __('Baris 1 adalah header, data mulai baris 2. Urutan kolom:') }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-xs border border-blue-200 rounded overflow-hidden">
                    <thead>
                        <tr class="bg-blue-100">
                            <th class="px-2 py-1 border-r border-blue-200">Kolom</th>
                            <th class="px-2 py-1">Header</th>
                            <th class="px-2 py-1 border-l border-blue-200">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            ['A', 'no', 'Angka posisi, boleh kosong (untuk mengatur urutan)'],
                            ['B', 'row', 'Teks, boleh kosong'],
                            ['C', 'kolom', 'Teks, boleh kosong'],
                            ['D', 'part_no', 'Wajib. Dicocokkan ke Part List, dibuat otomatis jika belum ada'],
                            ['E', 'lot_produksi', 'Angka, boleh kosong'],
                            ['F', 'slot', 'Angka, boleh kosong'],
                        ] as [$col, $header, $note])
                            <tr class="bg-white">
                                <td class="px-2 py-1 border-r border-t border-blue-200">{{ $col }}</td>
                                <td class="px-2 py-1 border-t border-blue-200 font-mono">{{ $header }}</td>
                                <td class="px-2 py-1 border-l border-t border-blue-200">{{ $note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="mt-3 space-y-1 list-disc list-inside text-blue-700">
                <li>{{ __('Baris dengan part_no kosong akan dilewati.') }}</li>
                <li>{{ __('Satu part = satu baris Lot Making. Part yang sudah ada akan diperbarui; part baru akan dibuat.') }}</li>
                <li>{{ __('Kolom angka yang kosong / bukan angka disimpan sebagai kosong.') }}</li>
                <li>{{ __('Avg Slot dan Slot Fix tidak diimport — selalu dihitung otomatis dari Lot Produksi ÷ Slot.') }}</li>
            </ul>
            <a href="{{ route('lot-makings.import.template') }}"
               class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-800 hover:text-blue-900 underline">
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
