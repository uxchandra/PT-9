<x-app-layout>
    <x-slot name="header">
        {{ __('Edit Part') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-lg bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('parts.update', $part) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <x-input-label for="part_no" :value="__('Part No')" />
                    <x-text-input id="part_no" name="part_no" type="text" class="block w-full mt-1" :value="old('part_no', $part->part_no)" required autofocus />
                    <x-input-error :messages="$errors->get('part_no')" class="mt-2" />
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Simpan') }}</x-primary-button>
                    <a href="{{ route('parts.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
