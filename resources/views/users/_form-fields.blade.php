{{-- Shared by create.blade.php and edit.blade.php. $user is null on create. --}}
@php
    $user = $user ?? null;
@endphp

<div>
    <x-input-label for="name" :value="__('Nama')" />
    <x-text-input id="name" name="name" type="text" class="block w-full mt-1" :value="old('name', $user?->name)" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="username" :value="__('Username')" />
    <x-text-input id="username" name="username" type="text" class="block w-full mt-1" :value="old('username', $user?->username)" required />
    <x-input-error :messages="$errors->get('username')" class="mt-2" />
</div>

<div>
    <x-input-label for="role" :value="__('Role')" />
    <select id="role" name="role" required
            class="mt-1 block w-full border-gray-300 focus:border-brand-700 focus:ring-brand-700 rounded-lg shadow-sm text-sm transition">
        <option value="">{{ __('— pilih role —') }}</option>
        @foreach ($roles as $role)
            <option value="{{ $role }}" @selected(old('role', $user?->roles->first()?->name) === $role)>{{ $role }}</option>
        @endforeach
    </select>
    <p class="mt-1 text-xs text-gray-400">{{ __('Satu user, satu role.') }}</p>
    <x-input-error :messages="$errors->get('role')" class="mt-2" />
</div>

<div>
    <x-input-label for="password" :value="$user ? __('Password (kosongkan jika tidak diubah)') : __('Password')" />
    <x-text-input id="password" name="password" type="password" class="block w-full mt-1"
                  autocomplete="new-password" :required="$user === null" />
    <x-input-error :messages="$errors->get('password')" class="mt-2" />
</div>

<div>
    <x-input-label for="password_confirmation" :value="__('Konfirmasi Password')" />
    <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="block w-full mt-1"
                  autocomplete="new-password" :required="$user === null" />
</div>
