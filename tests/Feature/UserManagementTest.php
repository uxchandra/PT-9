<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function superadminUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    }

    public function test_staff_cannot_manage_users(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('users.index'))->assertForbidden();
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee($admin->username);
    }

    public function test_admin_can_create_a_user_with_a_role(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Operator Satu',
            'username' => 'operator1',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'scanner',
        ]);

        $response->assertRedirect(route('users.index'));

        $user = User::where('username', 'operator1')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('scanner'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $user->password));
    }

    public function test_a_2_character_password_is_accepted(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Short Pass',
            'username' => 'shortpass',
            'password' => 'ab',
            'password_confirmation' => 'ab',
            'role' => 'staff',
        ])->assertRedirect(route('users.index'));

        $this->assertNotNull(User::where('username', 'shortpass')->first());
    }

    public function test_a_1_character_password_is_rejected(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Too Short',
            'username' => 'tooshort',
            'password' => 'a',
            'password_confirmation' => 'a',
            'role' => 'staff',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('username', 'tooshort')->first());
    }

    public function test_username_must_be_unique(): void
    {
        $admin = $this->adminUser();
        User::factory()->create(['username' => 'dupe']);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Another',
            'username' => 'dupe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'staff',
        ])->assertSessionHasErrors('username');
    }

    public function test_a_regular_admin_cannot_assign_the_superadmin_role(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Sneaky',
            'username' => 'sneaky',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'superadmin',
        ])->assertSessionHasErrors('role');

        $this->assertNull(User::where('username', 'sneaky')->first());
    }

    public function test_a_regular_admin_cannot_open_or_edit_a_superadmin_account(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create(['username' => 'superadmin_two']);
        $target->assignRole('superadmin');

        $this->actingAs($admin)->get(route('users.edit', $target))->assertForbidden();

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => 'Renamed',
            'username' => $target->username,
            'role' => 'admin',
        ])->assertForbidden();

        $this->actingAs($admin)->delete(route('users.destroy', $target))->assertForbidden();

        $this->assertSame('superadmin', $target->fresh()->roles->first()?->name);
    }

    public function test_a_superadmin_can_assign_the_superadmin_role_and_manage_other_superadmins(): void
    {
        $superadmin = $this->superadminUser();
        $target = User::factory()->create(['username' => 'promote_me']);
        $target->assignRole('admin');

        $this->actingAs($superadmin)->put(route('users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'role' => 'superadmin',
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($target->fresh()->hasRole('superadmin'));
    }

    public function test_updating_a_user_without_a_password_keeps_the_old_one(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create(['username' => 'staff_member', 'password' => bcrypt('original-pass')]);
        $target->assignRole('staff');

        $this->actingAs($admin)->put(route('users.update', $target), [
            'name' => 'Updated Name',
            'username' => $target->username,
            'role' => 'staff',
        ])->assertRedirect(route('users.index'));

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('original-pass', $target->fresh()->password));
        $this->assertSame('Updated Name', $target->fresh()->name);
    }

    public function test_a_user_cannot_delete_their_own_account(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))
            ->assertRedirect(route('users.index'));

        $this->assertNotNull($admin->fresh());
    }

    public function test_an_admin_can_delete_another_user(): void
    {
        $admin = $this->adminUser();
        $target = User::factory()->create();
        $target->assignRole('staff');

        $this->actingAs($admin)->delete(route('users.destroy', $target))
            ->assertRedirect(route('users.index'));

        $this->assertNull($target->fresh());
    }
}
