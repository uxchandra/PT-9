<x-app-layout>
    <x-slot name="header">
        {{ __('Import Kesei') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">

            <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
                <p class="font-semibold mb-1">{{ __('Format file (.xlsx, .xls, atau .csv)') }}</p>
                <p class="mb-2">{{ __('Baris 1 header, data mulai baris 2.') }}</p>
                <table class="text-xs border border-blue-200 rounded overflow-hidden">
                    <thead>
                        <tr class="bg-blue-100">
                            <th class="px-2 py-1 border-r border-blue-200">Kolom</th>
                            <th class="px-2 py-1">Header</th>
                            <th class="px-2 py-1 border-l border-blue-200">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-white">
                            <td class="px-2 py-1 border-r border-t border-blue-200">A</td>
                            <td class="px-2 py-1 border-t border-blue-200 font-mono">part_no</td>
                            <td class="px-2 py-1 border-l border-t border-blue-200">Wajib. Dicocokkan ke Part List, dibuat otomatis jika belum ada</td>
                        </tr>
                        <tr class="bg-white">
                            <td class="px-2 py-1 border-r border-t border-blue-200">B</td>
                            <td class="px-2 py-1 border-t border-blue-200 font-mono">stock_source</td>
                            <td class="px-2 py-1 border-l border-t border-blue-200">Opsional. Part no source (Timeline Stok), pisah koma. Kosong = pakai part no sendiri</td>
                        </tr>
                        <tr class="bg-white">
                            <td class="px-2 py-1 border-r border-t border-blue-200">C</td>
                            <td class="px-2 py-1 border-t border-blue-200 font-mono">closing_time</td>
                            <td class="px-2 py-1 border-l border-t border-blue-200">Opsional. Jam HH:MM (mis. 14:30)</td>
                        </tr>
                        <tr class="bg-white">
                            <td class="px-2 py-1 border-r border-t border-blue-200">D</td>
                            <td class="px-2 py-1 border-t border-blue-200 font-mono">pattern</td>
                            <td class="px-2 py-1 border-l border-t border-blue-200">Opsional. Nama Pattern Board, bisa lebih dari satu dipisah koma (mis. A, B). Nama yang tidak ada boardnya diabaikan</td>
                        </tr>
                    </tbody>
                </table>
                <ul class="mt-3 space-y-1 list-disc list-inside text-blue-700">
                    <li>{{ __('Part yang sudah ada di Kesei tidak ditambah lagi. Kalau baris membawa stock_source, sumber stoknya saja yang diperbarui; kalau kosong, baris dilewati.') }}</li>
                    <li>{{ __('part_no yang muncul lebih dari sekali dalam file hanya ditambahkan sekali.') }}</li>
                    <li>{{ __('Baris dengan part_no kosong akan dilewati.') }}</li>
                </ul>
                <a href="{{ route('kesei.import.template') }}"
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

            <form method="POST" action="{{ route('kesei.import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="file" :value="__('File Excel/CSV')" />
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-800 file:text-white hover:file:bg-brand-900" />
                    <x-input-error :messages="$errors->get('file')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Import') }}</x-primary-button>
                    <a href="{{ route('kesei.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
