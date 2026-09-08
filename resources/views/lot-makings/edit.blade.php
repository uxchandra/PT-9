<x-app-layout>
    <x-slot name="header">
        {{ __('Edit Lot Making') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="max-w-3xl bg-white border border-gray-100 shadow-sm rounded-2xl p-6">
            <form method="POST" action="{{ route('lot-makings.update', $lotMaking) }}">
                @method('PUT')
                @include('lot-makings._form', ['lm' => $lotMaking])
            </form>
        </div>
    </div>
</x-app-layout>
