<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScannerTest extends TestCase
{
    use RefreshDatabase;

    private function scannerUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('scanner');

        return $user;
    }

    public function test_a_scanner_user_hitting_the_dashboard_is_bounced_to_the_scanner(): void
    {
        // Login always aims for /dashboard; a scanner user must not dead-end there.
        $this->actingAs($this->scannerUser())
            ->get(route('dashboard'))
            ->assertRedirect(route('scanner.dashboard'));
    }

    public function test_scanner_dashboard_shows_the_title_and_both_location_cards(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.dashboard'))
            ->assertOk()
            ->assertSee('KESEI KANBAN')
            ->assertSee('Finish Goods')
            ->assertSee('Store 3');
    }

    public function test_a_non_scanner_user_without_dashboard_access_still_gets_403(): void
    {
        (new RolePermissionSeeder)->run();
        $user = User::factory()->create(); // no roles at all

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($user)->get(route('scanner.dashboard'))->assertForbidden();
    }

    public function test_staff_still_sees_the_normal_dashboard(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('dashboard'))->assertOk();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('scanner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_location_page_shows_the_location_and_a_scan_input(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'finish-goods'))
            ->assertOk()
            ->assertSee('FINISH GOODS')
            ->assertSee('id="scan-input"', false);
    }

    public function test_an_unknown_location_is_404(): void
    {
        $this->actingAs($this->scannerUser())
            ->get(route('scanner.location', 'warehouse-x'))
            ->assertNotFound();
    }
}
