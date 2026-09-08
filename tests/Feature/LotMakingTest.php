<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LotMakingTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_index_requires_the_manage_lot_making_permission(): void
    {
        $this->get(route('lot-makings.index'))->assertRedirect(route('login'));

        (new RolePermissionSeeder)->run();
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->get(route('lot-makings.index'))->assertForbidden();

        $this->actingAs($this->authorizedUser())->get(route('lot-makings.index'))->assertOk();
    }

    public function test_a_lot_making_can_be_created(): void
    {
        $part = Part::create(['part_no' => 'P1']);

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), [
                'assy_part_code' => 'ASSY-1',
                'part_id' => $part->id,
                'qty_kanban' => 20,
                'lot' => 100,
                'loading_time' => 40,
                'dandori' => 10,
                'lot_produksi' => 200,
                'safety_stock' => 50,
                'total_kanban_edar' => 12,
                'next_process' => 'Assembly',
                'kapasitas_rak' => 30,
            ]);

        $response->assertRedirect(route('lot-makings.index'));

        $lm = LotMaking::first();
        $this->assertSame('ASSY-1', $lm->assy_part_code);
        $this->assertSame($part->id, $lm->part_id);
        $this->assertSame(200, $lm->lot_produksi);
        $this->assertSame('Assembly', $lm->next_process);
    }

    public function test_assy_part_code_and_part_are_required(): void
    {
        $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), ['lot' => 5])
            ->assertSessionHasErrors(['assy_part_code', 'part_id']);

        $this->assertSame(0, LotMaking::count());
    }

    public function test_numeric_fields_reject_negative_values(): void
    {
        $part = Part::create(['part_no' => 'P1']);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), [
                'assy_part_code' => 'ASSY-1',
                'part_id' => $part->id,
                'safety_stock' => -3,
            ])
            ->assertSessionHasErrors('safety_stock');
    }

    public function test_a_lot_making_can_be_updated_and_deleted(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $lm = LotMaking::create(['assy_part_code' => 'ASSY-1', 'part_id' => $part->id, 'lot' => 100]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-makings.update', $lm), [
                'assy_part_code' => 'ASSY-1',
                'part_id' => $part->id,
                'lot' => 250,
            ])
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(250, $lm->fresh()->lot);

        $this->actingAs($this->authorizedUser())
            ->delete(route('lot-makings.destroy', $lm))
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(0, LotMaking::count());
    }

    public function test_index_search_filters_by_assy_code_and_part_no(): void
    {
        $partA = Part::create(['part_no' => 'AAA-111']);
        $partB = Part::create(['part_no' => 'BBB-222']);
        LotMaking::create(['assy_part_code' => 'ALPHA', 'part_id' => $partA->id]);
        LotMaking::create(['assy_part_code' => 'BETA', 'part_id' => $partB->id]);

        $byCode = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index', ['q' => 'ALPHA']))->getContent();
        $this->assertStringContainsString('ALPHA', $byCode);
        $this->assertStringNotContainsString('BETA', $byCode);

        $byPartNo = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index', ['q' => 'BBB-222']))->getContent();
        $this->assertStringContainsString('BETA', $byPartNo);
        $this->assertStringNotContainsString('ALPHA', $byPartNo);
    }

    public function test_import_creates_rows_resolves_parts_and_updates_existing(): void
    {
        Part::create(['part_no' => 'EXISTING-PART']);
        $user = $this->authorizedUser();

        $csv = implode("\n", [
            'assy_part_code,part_no,qty_kanban,lot,loading_time,dandori,lot_produksi,safety_stock,total_kanban_edar,next_process,kapasitas_rak',
            'ASSY-1,EXISTING-PART,20,100,40,10,200,50,12,Assembly,30',
            'ASSY-2,NEW-PART,16,50,160,10,120,24,8,Welding,24',
            ',SKIP-ME,1,1,1,1,1,1,1,x,1',
        ]);

        $this->actingAs($user)
            ->post(route('lot-makings.import.store'), ['file' => $this->csvUpload($csv)])
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(2, LotMaking::count());
        $this->assertNotNull(Part::where('part_no', 'NEW-PART')->first(), 'missing part is created');
        $this->assertNull(Part::where('part_no', 'SKIP-ME')->first(), 'row without assy_part_code is skipped');

        $row1 = LotMaking::where('assy_part_code', 'ASSY-1')->first();
        $this->assertSame(200, $row1->lot_produksi);
        $this->assertSame('Assembly', $row1->next_process);

        // Re-import with a changed value for the same assy_part_code + part → update, not duplicate.
        $csv2 = implode("\n", [
            'assy_part_code,part_no,qty_kanban,lot,loading_time,dandori,lot_produksi,safety_stock,total_kanban_edar,next_process,kapasitas_rak',
            'ASSY-1,EXISTING-PART,20,999,40,10,200,50,12,Assembly,30',
        ]);
        $this->actingAs($user)->post(route('lot-makings.import.store'), ['file' => $this->csvUpload($csv2)]);

        $this->assertSame(2, LotMaking::count());
        $this->assertSame(999, LotMaking::where('assy_part_code', 'ASSY-1')->first()->lot);
    }

    public function test_import_template_downloads_an_xlsx(): void
    {
        $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.import.template'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=template-import-lot-making.xlsx');
    }

    private function csvUpload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lm').'.csv';
        // Trailing newline: the CSV reader drops the final line without it.
        file_put_contents($path, rtrim($contents, "\n")."\n");

        return new UploadedFile($path, 'lot-making.csv', 'text/csv', null, true);
    }
}
