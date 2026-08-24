<x-app-layout>
    <x-slot name="header">
        {{ __('Rest') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-100">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">{{ __('Daftar Rest') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $rests->total() }} {{ __('jadwal istirahat') }}</p>
                </div>
                <a href="{{ route('rests.create') }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
                    {{ __('Tambah Rest') }}
                </a>
            </div>

            @if (session('status'))
                <div class="mx-6 mt-6 flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 font-medium text-sm text-green-700">
                    <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-6 py-3">{{ __('Nama') }}</th>
                            <th class="px-6 py-3">{{ __('Mulai') }}</th>
                            <th class="px-6 py-3">{{ __('Selesai') }}</th>
                            <th class="px-6 py-3 w-40 text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rests as $rest)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-800">{{ $rest->name }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $rest->start_time->format('H:i') }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $rest->end_time->format('H:i') }}</td>
                                <td class="px-6 py-3 text-right">
                                    <a href="{{ route('rests.edit', $rest) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                                    <form action="{{ route('rests.destroy', $rest) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus jadwal rest ini?') }}');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data rest.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rests->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $rests->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
