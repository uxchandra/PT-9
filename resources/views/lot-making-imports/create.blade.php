<x-app-layout>
    <x-slot name="header">
        {{ __('Import Lot Making') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-2xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">

            <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
                <p class="font-semibold mb-1">{{ __('Format file (.xlsx, .xls, atau .csv)') }}</p>
                <p class="mb-2">{{ __('Baris 1 adalah header, data mulai baris 2. Urutan kolom:') }}</p>
                <div class="overflow-x-auto">
                    <table class="text-xs border border-blue-200 rounded overflow-hidden">
                        <thead>
                            <tr class="bg-blue-100">
                                <th class="px-2 py-1 border-r border-blue-200">Kolom</th>
                                <th class="px-2 py-1">Header</th>
                                <th class="px-2 py-1 border-l border-blue-200">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                ['A', 'assy_part_code', 'Wajib. Kode assy part'],
                                ['B', 'part_no', 'Wajib. Dicocokkan ke Part List, dibuat otomatis jika belum ada'],
                                ['C', 'qty_kanban', 'Angka, boleh kosong'],
                                ['D', 'lot', 'Angka, boleh kosong'],
                                ['E', 'loading_time', 'Angka (menit), boleh kosong'],
                                ['F', 'dandori', 'Angka (menit), boleh kosong'],
                                ['G', 'lot_produksi', 'Angka, boleh kosong'],
                                ['H', 'safety_stock', 'Angka, boleh kosong'],
                                ['I', 'total_kanban_edar', 'Angka, boleh kosong'],
                                ['J', 'next_process', 'Teks, boleh kosong'],
                                ['K', 'kapasitas_rak', 'Angka, boleh kosong'],
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
                    <li>{{ __('Baris dengan assy_part_code atau part_no kosong akan dilewati.') }}</li>
                    <li>{{ __('Kombinasi assy_part_code + part_no yang sudah ada akan diperbarui; kombinasi baru akan dibuat.') }}</li>
                    <li>{{ __('Kolom angka yang kosong / bukan angka disimpan sebagai kosong.') }}</li>
                </ul>
                <a href="{{ route('lot-makings.import.template') }}"
                   class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-800 hover:text-blue-900 underline">
                    {{ __('Unduh template Excel') }}
                </a>
            </div>

            @if (session('status'))
                <div class="mb-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                    <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('lot-makings.import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="file" :value="__('File Excel/CSV')" />
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-800 file:text-white hover:file:bg-brand-900" />
                    <x-input-error :messages="$errors->get('file')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Import') }}</x-primary-button>
                    <a href="{{ route('lot-makings.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
