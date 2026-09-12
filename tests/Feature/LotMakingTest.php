<?php

namespace Tests\Feature;

use App\Models\LotMaking;
use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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
                'no' => 3,
                'part_id' => $part->id,
                'row' => 'A',
                'kolom' => '1',
                'lot_produksi' => 200,
                'slot' => 40,
            ]);

        $response->assertRedirect(route('lot-makings.index'));

        $lm = LotMaking::first();
        $this->assertSame(3, $lm->no);
        $this->assertSame($part->id, $lm->part_id);
        $this->assertSame('A', $lm->row);
        $this->assertSame('1', $lm->kolom);
        $this->assertSame(200, $lm->lot_produksi);
        $this->assertSame(40, $lm->slot);
    }

    public function test_no_is_the_manually_set_position_that_drives_listing_order(): void
    {
        $partA = Part::create(['part_no' => 'AAA-111']);
        $partB = Part::create(['part_no' => 'BBB-222']);
        // Created out of order, but "no" should decide the listing order.
        LotMaking::create(['part_id' => $partA->id, 'no' => 2]);
        LotMaking::create(['part_id' => $partB->id, 'no' => 1]);

        $html = $this->actingAs($this->authorizedUser())->get(route('lot-makings.index'))->getContent();

        $this->assertLessThan(strpos($html, 'AAA-111'), strpos($html, 'BBB-222'));
    }

    public function test_part_is_required(): void
    {
        $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), ['row' => 'A'])
            ->assertSessionHasErrors(['part_id']);

        $this->assertSame(0, LotMaking::count());
    }

    public function test_numeric_fields_reject_negative_or_zero_values(): void
    {
        $part = Part::create(['part_no' => 'P1']);

        $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), [
                'part_id' => $part->id,
                'lot_produksi' => -3,
            ])
            ->assertSessionHasErrors('lot_produksi');

        // slot is a divisor — 0 would make avg_slot/slot_fix meaningless.
        $this->actingAs($this->authorizedUser())
            ->post(route('lot-makings.store'), [
                'part_id' => $part->id,
                'slot' => 0,
            ])
            ->assertSessionHasErrors('slot');
    }

    public function test_avg_slot_and_slot_fix_are_computed_not_stored(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $lm = LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 205, 'slot' => 40]);

        $this->assertEqualsWithDelta(5.125, $lm->avg_slot, 0.0001);
        $this->assertSame(6, $lm->slot_fix); // ROUNDUP(5.125) = 6

        $this->assertFalse(Schema::hasColumn('lot_makings', 'avg_slot'));
        $this->assertFalse(Schema::hasColumn('lot_makings', 'slot_fix'));

        // No slot set yet → both are null, not a division-by-zero error.
        $noSlot = LotMaking::create(['part_id' => Part::create(['part_no' => 'P2'])->id, 'lot_produksi' => 100]);
        $this->assertNull($noSlot->avg_slot);
        $this->assertNull($noSlot->slot_fix);
    }

    public function test_a_lot_making_can_be_updated_and_deleted(): void
    {
        $part = Part::create(['part_no' => 'P1']);
        $lm = LotMaking::create(['part_id' => $part->id, 'lot_produksi' => 100]);

        $this->actingAs($this->authorizedUser())
            ->put(route('lot-makings.update', $lm), [
                'part_id' => $part->id,
                'lot_produksi' => 250,
                'slot' => 50,
            ])
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(250, $lm->fresh()->lot_produksi);
        $this->assertSame(5, $lm->fresh()->slot_fix);

        $this->actingAs($this->authorizedUser())
            ->delete(route('lot-makings.destroy', $lm))
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(0, LotMaking::count());
    }

    public function test_index_search_filters_by_row_kolom_and_part_no(): void
    {
        $partA = Part::create(['part_no' => 'AAA-111']);
        $partB = Part::create(['part_no' => 'BBB-222']);
        LotMaking::create(['part_id' => $partA->id, 'row' => 'ROWALPHA', 'kolom' => '1']);
        LotMaking::create(['part_id' => $partB->id, 'row' => 'ROWBETA', 'kolom' => '2']);

        $byRow = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index', ['q' => 'ROWALPHA']))->getContent();
        $this->assertStringContainsString('ROWALPHA', $byRow);
        $this->assertStringNotContainsString('ROWBETA', $byRow);

        $byPartNo = $this->actingAs($this->authorizedUser())
            ->get(route('lot-makings.index', ['q' => 'BBB-222']))->getContent();
        $this->assertStringContainsString('ROWBETA', $byPartNo);
        $this->assertStringNotContainsString('ROWALPHA', $byPartNo);
    }

    public function test_import_creates_rows_resolves_parts_and_updates_existing(): void
    {
        Part::create(['part_no' => 'EXISTING-PART']);
        $user = $this->authorizedUser();

        $csv = implode("\n", [
            'no,row,kolom,part_no,lot_produksi,slot',
            '1,A,1,EXISTING-PART,200,40',
            '2,A,2,NEW-PART,120,30',
            '3,B,3,,1,1', // blank part_no -> skipped
        ]);

        $this->actingAs($user)
            ->post(route('lot-makings.import.store'), ['file' => $this->csvUpload($csv)])
            ->assertRedirect(route('lot-makings.index'));

        $this->assertSame(2, LotMaking::count());
        $this->assertNotNull(Part::where('part_no', 'NEW-PART')->first(), 'missing part is created');

        $row1 = LotMaking::whereHas('part', fn ($q) => $q->where('part_no', 'EXISTING-PART'))->first();
        $this->assertSame(1, $row1->no);
        $this->assertSame(200, $row1->lot_produksi);
        $this->assertSame('A', $row1->row);
        $this->assertSame(5, $row1->slot_fix);

        // Re-importing the same part updates in place instead of duplicating.
        $csv2 = implode("\n", [
            'no,row,kolom,part_no,lot_produksi,slot',
            '9,A,1,EXISTING-PART,999,40',
        ]);
        $this->actingAs($user)->post(route('lot-makings.import.store'), ['file' => $this->csvUpload($csv2)]);

        $this->assertSame(2, LotMaking::count());
        $this->assertSame(999, $row1->fresh()->lot_produksi);
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
