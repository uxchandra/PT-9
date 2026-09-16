<x-app-layout>
    <x-slot name="header">
        {{ __('Edit User') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-lg bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-5">
                @csrf
                @method('PUT')
                @include('users._form-fields', ['user' => $user])

                <div class="flex items-center gap-3">
                    <x-primary-button>{{ __('Simpan') }}</x-primary-button>
                    <a href="{{ route('users.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ __('Batal') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
