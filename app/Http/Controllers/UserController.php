<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Account management for this system's own logins — name/username/password
 * plus exactly one role. 'manage users' is granted to admin as well as
 * superadmin (see RolePermissionSeeder), but superadmin is a special case
 * two ways Gate::before's blanket bypass doesn't cover on its own: a regular
 * admin must never be able to hand out the superadmin role, or open/edit/
 * delete an account that already has it — both guarded explicitly below.
 */
class UserController extends Controller
{
    public function index(): View
    {
        $users = User::with('roles')->orderBy('name')->paginate(15);

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.create', ['roles' => $this->assignableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.index')->with('status', 'User berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        $this->guardAgainstSuperadminTarget($user);

        return view('users.edit', ['user' => $user, 'roles' => $this->assignableRoles()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->guardAgainstSuperadminTarget($user);

        $validated = $this->validated($request, $user);

        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
        ]);

        if (! empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.index')->with('status', 'User berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardAgainstSuperadminTarget($user);

        if ($user->id === $request->user()->id) {
            return redirect()->route('users.index')->with('error', 'Tidak bisa menghapus akun sendiri.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('status', 'User berhasil dihapus.');
    }

    /**
     * Roles the acting user may hand out. A superadmin sees every role; a
     * regular admin sees every role EXCEPT superadmin, so managing accounts
     * day to day never doubles as a way to mint another superadmin.
     *
     * @return Collection<int, string>
     */
    private function assignableRoles(): Collection
    {
        $roles = Role::orderBy('name')->pluck('name');

        return auth()->user()->hasRole('superadmin')
            ? $roles
            : $roles->reject(fn (string $name) => $name === 'superadmin')->values();
    }

    /**
     * Only a superadmin may open, save, or delete another superadmin's
     * account — otherwise a regular admin (who can manage users) could
     * rename, reset the password of, or remove the one account type it's
     * not allowed to create.
     */
    private function guardAgainstSuperadminTarget(User $user): void
    {
        abort_if($user->hasRole('superadmin') && ! auth()->user()->hasRole('superadmin'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($user),
            ],
            'password' => $user === null
                ? ['required', 'confirmed', Password::defaults()]
                : ['nullable', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in($this->assignableRoles()->all())],
        ]);
    }
}
