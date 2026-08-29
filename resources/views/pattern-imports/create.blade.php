<x-app-layout>
    <x-slot name="header">
        {{ __('Import Pattern') }} — {{ $patternBoard->name }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-2xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">

            <div class="mb-6 flex items-center justify-between gap-4 rounded-lg border border-brand-100 bg-brand-50 p-4">
                <div>
                    <p class="font-semibold text-brand-900 text-sm">{{ __('Belum punya file? Download template dulu') }}</p>
                    <p class="text-xs text-brand-700 mt-0.5">{{ __('Kolomnya sudah pas, tinggal isi datanya lalu upload lagi di sini.') }}</p>
                </div>
                <a href="{{ route('pattern-boards.import.template') }}"
                   class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2 bg-brand-800 rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    {{ __('Download Template') }}
                </a>
            </div>

            <div class="mb-6 rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">
                <p class="font-semibold mb-1">{{ __('Format file (.xlsx, .xls, atau .csv)') }}</p>
                <p class="mb-2">{{ __('Baris pertama harus header dengan kolom persis seperti ini:') }}</p>
                <div class="overflow-x-auto">
                    <table class="text-xs border border-blue-200 rounded overflow-hidden">
                        <thead>
                            <tr class="bg-blue-100">
                                <th class="px-2 py-1 border-r border-blue-200">Machine</th>
                                <th class="px-2 py-1 border-r border-blue-200">Item</th>
                                <th class="px-2 py-1 border-r border-blue-200">Jumlah Proses</th>
                                <th class="px-2 py-1 border-r border-blue-200">Proses</th>
                                <th class="px-2 py-1 border-r border-blue-200">loading_time</th>
                                <th class="px-2 py-1 border-r border-blue-200">dandori</th>
                                <th class="px-2 py-1 border-r border-blue-200">Shift</th>
                                <th class="px-2 py-1">lot</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="bg-white">
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">PT91</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">GA241-04750</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">9</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">2</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">40</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">10</td>
                                <td class="px-2 py-1 border-r border-blue-200 border-t border-blue-200">1</td>
                                <td class="px-2 py-1 border-t border-blue-200">100</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="mt-3 space-y-1 list-disc list-inside text-blue-700">
                    <li>{{ __('Satu baris = satu assignment machine + part. Machine dan Item yang belum ada otomatis dibuat.') }}</li>
                    <li>{{ __('loading_time, Jumlah Proses, dandori, lot disimpan di Kelompok Pattern per part+shift — diambil dari baris pertama part+shift tersebut ditemukan. Kalau baris lain untuk part+shift yang sama beda nilainya, baris itu dilewati untuk kolom-kolom tersebut (assignment mesinnya tetap disimpan).') }}</li>
                    <li>{{ __('Proses & Machine tetap disimpan per baris (per assignment).') }}</li>
                    <li>{{ __('Shift: isi 1 untuk Shift 1 (07:00–16:00) atau 2 untuk Shift 2 (20:00–06:00). Kolom ini boleh dikosongkan / tidak ada — defaultnya Shift 1.') }}</li>
                    <li>{{ __('lot: boleh dikosongkan / tidak ada — defaultnya 0.') }}</li>
                    <li>{{ __('Total Kanban tidak diinput manual/dari file — otomatis dihitung dari Lot ÷ Qty Kbn part (di Part List), dibulatkan ke atas. Pastikan Qty Kbn part sudah terisi di Part List.') }}</li>
                </ul>
            </div>

            @if (session('importMismatches') && count(session('importMismatches')) > 0)
                <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-semibold mb-1">{{ __('Ada beberapa baris dengan nilai berbeda dari yang tersimpan:') }}</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach (session('importMismatches') as $mismatch)
                            <li>{{ $mismatch }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('pattern-boards.import.store', $patternBoard) }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div>
                    <x-input-label for="file" :value="__('File Excel/CSV')" />
                    <input id="file" name="file" type="file" accept=".xlsx,.xls,.csv" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-800 file:text-white hover:file:bg-brand-900" />
                    <x-input-error :messages="$errors->get('file')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Import') }}</x-primary-button>
                    <a href="{{ route('pattern-boards.index', ['board' => $patternBoard->id]) }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
