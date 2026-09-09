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

    public function test_a_scanner_user_lands_on_the_scanner_dashboard_after_login(): void
    {
        $user = $this->scannerUser();

        $this->post('/login', ['username' => $user->username, 'password' => 'password'])
            ->assertRedirect(route('scanner.dashboard', absolute: false));

        $this->assertAuthenticated();
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

    public function test_a_non_scanner_user_cannot_open_the_scanner(): void
    {
        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get(route('scanner.dashboard'))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('scanner.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_location_page_shows_the_location_name_and_a_scan_input(): void
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
