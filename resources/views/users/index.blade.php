<x-app-layout>
    <x-slot name="header">
        {{ __('User') }}
    </x-slot>

    <div class="p-4 sm:p-6 lg:p-8">
        <div class="bg-white border border-gray-100 shadow-sm rounded-2xl overflow-hidden">
            <div class="flex items-center justify-between p-6 border-b border-gray-100">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">{{ __('Daftar User') }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $users->total() }} {{ __('user terdaftar') }}</p>
                </div>
                <a href="{{ route('users.create') }}"
                   class="inline-flex items-center justify-center px-4 py-2.5 bg-brand-800 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-brand-900 transition ease-in-out duration-150 shadow-sm">
                    {{ __('Tambah User') }}
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

            @if (session('error'))
                <div class="mx-6 mt-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 font-medium text-sm text-red-700">
                    <svg class="w-5 h-5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                    </svg>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-6 py-3">{{ __('Nama') }}</th>
                            <th class="px-6 py-3">{{ __('Username') }}</th>
                            <th class="px-6 py-3">{{ __('Role') }}</th>
                            <th class="px-6 py-3 w-40 text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($users as $user)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-800 font-medium">
                                    {{ $user->name }}
                                    @if ($user->id === auth()->id())
                                        <span class="ml-1 text-xs text-gray-400">({{ __('Anda') }})</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-gray-600">&commat;{{ $user->username }}</td>
                                <td class="px-6 py-3">
                                    @forelse ($user->roles as $role)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-brand-50 text-brand-800 border border-brand-100">{{ $role->name }}</span>
                                    @empty
                                        <span class="text-xs text-gray-400">{{ __('— tanpa role —') }}</span>
                                    @endforelse
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if ($user->hasRole('superadmin') && ! auth()->user()->hasRole('superadmin'))
                                        <span class="text-xs text-gray-400">{{ __('Terkunci') }}</span>
                                    @else
                                        <a href="{{ route('users.edit', $user) }}" class="text-brand-700 hover:text-brand-900 font-medium">{{ __('Edit') }}</a>
                                        @unless ($user->id === auth()->id())
                                            <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline" onsubmit="return confirm('{{ __('Hapus user ini?') }}');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="ml-3 text-red-600 hover:text-red-800 font-medium">{{ __('Hapus') }}</button>
                                            </form>
                                        @endunless
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-gray-400">{{ __('Belum ada data user.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="px-6 py-4 border-t border-gray-100">
                    {{ $users->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
