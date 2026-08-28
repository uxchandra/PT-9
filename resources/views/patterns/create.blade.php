<x-app-layout>
    <x-slot name="header">
        {{ __('Tambah Assignment Mesin') }} — {{ $patternBoard->name }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-lg bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('pattern-boards.patterns.store', $patternBoard) }}" class="space-y-5">
                @csrf

                <div>
                    <x-input-label :value="__('Pattern Board')" />
                    <p class="mt-1 text-sm font-medium text-gray-800">{{ $patternBoard->name }}</p>
                </div>

                <div>
                    <x-input-label for="shift" :value="__('Shift')" />
                    <select id="shift" name="shift" required
                            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                        @foreach (\App\Models\Pattern::SHIFT_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(old('shift', 1) == $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('shift')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="machine_id" :value="__('Machine')" />
                    <select id="machine_id" name="machine_id" required
                            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                        <option value="">{{ __('-- Pilih Machine --') }}</option>
                        @foreach ($machines as $machine)
                            <option value="{{ $machine->id }}" @selected(old('machine_id') == $machine->id)>{{ $machine->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('machine_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="part_id" :value="__('Part')" />
                    <select id="part_id" name="part_id" required
                            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
                        <option value="">{{ __('-- Pilih Part --') }}</option>
                        @foreach ($parts as $part)
                            <option value="{{ $part->id }}" @selected(old('part_id') == $part->id)>{{ $part->part_no }}</option>
                        @endforeach
                    </select>
                    @if ($parts->isEmpty())
                        <p class="mt-1 text-xs text-amber-600">{{ __('Belum ada part di Kelompok Pattern board ini. Tambahkan dulu di bagian Kelompok Pattern.') }}</p>
                    @endif
                    <x-input-error :messages="$errors->get('part_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="proses" :value="__('Proses ke-')" />
                    <x-text-input id="proses" name="proses" type="number" min="1" class="block w-full mt-1" :value="old('proses')" required />
                    <p class="mt-1 text-xs text-gray-500">{{ __('Part yang sama bisa punya nomor proses berbeda di mesin lain, mis. proses 1/3 di mesin ini, 2/3 di mesin lain.') }}</p>
                    <x-input-error :messages="$errors->get('proses')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Simpan') }}</x-primary-button>
                    <a href="{{ route('pattern-boards.index', ['board' => $patternBoard->id]) }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
