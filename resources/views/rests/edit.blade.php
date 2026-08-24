<x-app-layout>
    <x-slot name="header">
        {{ __('Edit Rest') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-lg bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('rests.update', $rest) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <x-input-label for="name" :value="__('Nama')" />
                    <x-text-input id="name" name="name" type="text" class="block w-full mt-1" :value="old('name', $rest->name)" required autofocus />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="start_time" :value="__('Mulai')" />
                        <x-text-input id="start_time" name="start_time" type="time" class="block w-full mt-1" :value="old('start_time', $rest->start_time->format('H:i'))" required />
                        <x-input-error :messages="$errors->get('start_time')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="end_time" :value="__('Selesai')" />
                        <x-text-input id="end_time" name="end_time" type="time" class="block w-full mt-1" :value="old('end_time', $rest->end_time->format('H:i'))" required />
                        <x-input-error :messages="$errors->get('end_time')" class="mt-2" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Simpan') }}</x-primary-button>
                    <a href="{{ route('rests.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
