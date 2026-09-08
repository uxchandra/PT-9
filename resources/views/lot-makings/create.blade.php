<x-app-layout>
    <x-slot name="header">
        {{ __('Tambah Lot Making') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-3xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('lot-makings.store') }}">
                @include('lot-makings._form')
            </form>
        </div>
    </div>
</x-app-layout>
