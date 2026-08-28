<x-app-layout>
    <x-slot name="header">
        {{ __('Import Part') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-2xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">

            <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
                <p class="font-semibold mb-1">{{ __('Format file (.xlsx, .xls, atau .csv)') }}</p>
                <p class="mb-2">{{ __('Sesuai format Part List: baris 1 judul, baris 2 adalah header dengan kolom mulai dari kolom B, data mulai baris 3, urutan kolom sebagai berikut:') }}</p>
                <div class="overflow-x-auto">
                    <table class="text-xs border border-blue-200 rounded overflow-hidden">
                        <thead>
                            <tr class="bg-blue-100">
                                <th class="px-2 py-1 border-r border-blue-200">Kolom</th>
                                <th class="px-2 py-1">Header</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ([
                                'B' => 'ID', 'C' => 'Level', 'D' => 'Customer_Code', 'E' => 'Model',
                                'F' => 'Part_No', 'G' => 'Part_No_Fg', 'H' => 'Job_No', 'I' => 'Part_Name',
                                'J' => 'Type_Box', 'K' => 'Qty_Kbn', 'L' => 'process', 'M' => 'line',
                                'N' => 'line_code', 'O' => 'rack_no', 'P' => 'cap_rack', 'Q' => 'jig_no',
                                'R' => 'qty_lot', 'S' => 'stock_min', 'T' => 'stock_max', 'U' => 'image_name',
                                'V' => 'last_routing', 'W' => 'remark', 'X' => 'lt_pull', 'Y' => 'lt_prod',
                                'Z' => 'code_partset', 'AA' => 'set_label', 'AB' => 'prod_point', 'AC' => 'cat_machine',
                                'AD' => 'spm', 'AE' => 'dandory', 'AF' => 'cek_startfinish', 'AG' => 'update_by',
                                'AH' => 'update_time',
                            ] as $col => $header)
                                <tr class="bg-white">
                                    <td class="px-2 py-1 border-r border-t border-blue-200">{{ $col }}</td>
                                    <td class="px-2 py-1 border-t border-blue-200">{{ $header }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <ul class="mt-3 space-y-1 list-disc list-inside text-blue-700">
                    <li>{{ __('Kolom ID tidak dipakai — part dikenali dan dicocokkan lewat Part_No.') }}</li>
                    <li>{{ __('Part_No yang sudah ada akan diperbarui datanya; Part_No baru akan dibuat otomatis.') }}</li>
                    <li>{{ __('Baris dengan Part_No kosong akan dilewati.') }}</li>
                </ul>
            </div>

            @if (session('status'))
                <div class="mb-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                    <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('parts.import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="file" :value="__('File Excel/CSV')" />
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-800 file:text-white hover:file:bg-brand-900" />
                    <x-input-error :messages="$errors->get('file')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Import') }}</x-primary-button>
                    <a href="{{ route('parts.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
