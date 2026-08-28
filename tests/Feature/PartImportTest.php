<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PartImportTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function header(): string
    {
        return ',ID,Level,Customer_Code,Model,Part_No,Part_No_Fg,Job_No,Part_Name,Type_Box,Qty_Kbn,'
            .'process,line,line_code,rack_no,cap_rack,jig_no,qty_lot,stock_min,stock_max,image_name,'
            ."last_routing,remark,lt_pull,lt_prod,code_partset,set_label,prod_point,cat_machine,spm,dandory,cek_startfinish,update_by,update_time\n";
    }

    /** Minimal data row: only ID (col B) and Part_No (col F) filled in, rest left blank. */
    private function row(int $id, string $partNo): string
    {
        $cols = array_fill(0, 34, '');
        $cols[1] = (string) $id;
        $cols[5] = $partNo;

        return implode(',', $cols)."\n";
    }

    public function test_guests_cannot_import(): void
    {
        $response = $this->post(route('parts.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('import.csv', ",banner\n".$this->header()),
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_import_reads_headers_from_row_two_starting_at_column_b(): void
    {
        $csv = ",,,banner row is ignored\n"
            .$this->header()
            .',1,A,CUST01,ModelX,GA241-04750,GA241-04750-FG,JOB01,Part Name X,BoxA,10,'
            ."Proc1,Line1,LC1,Rack1,Cap1,Jig1,50,5,100,img1.png,Route1,Remark1,1,2,CPS1,SetA,Point1,Mach1,100,5,CekOK,admin,2026-08-27\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('parts.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertRedirect(route('parts.index'));
        $response->assertSessionHas('status');
        $this->assertStringContainsString('1 part baru', session('status'));

        $part = Part::where('part_no', 'GA241-04750')->first();
        $this->assertNotNull($part);
        $this->assertSame('GA241-04750-FG', $part->part_no_fg);
        $this->assertSame('CUST01', $part->customer_code);
        $this->assertSame('ModelX', $part->model);
        $this->assertSame('Part Name X', $part->part_name);
        $this->assertSame('img1.png', $part->image_name);
        $this->assertSame('CekOK', $part->cek_startfinish);
    }

    public function test_reimporting_an_existing_part_no_updates_it_instead_of_duplicating(): void
    {
        Part::create(['part_no' => 'GA241-04750', 'model' => 'Old']);

        $csv = ",banner\n"
            .$this->header()
            .',1,A,CUST01,NewModel,GA241-04750,GA241-04750-FG,JOB01,Part Name X,BoxA,10,'
            ."Proc1,Line1,LC1,Rack1,Cap1,Jig1,50,5,100,img1.png,Route1,Remark1,1,2,CPS1,SetA,Point1,Mach1,100,5,CekOK,admin,2026-08-27\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('parts.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('1 part diperbarui', session('status'));

        $this->assertSame(1, Part::count());
        $this->assertSame('NewModel', Part::first()->model);
    }

    public function test_rows_missing_part_no_are_skipped(): void
    {
        $csv = ",banner\n"
            .$this->header()
            .",1,A,CUST01,ModelX,,GA241-04750-FG,JOB01,Part Name X,BoxA,10,Proc1,Line1,LC1,Rack1,Cap1,Jig1,50,5,100,img1.png,Route1,Remark1,1,2,CPS1,SetA,Point1,Mach1,100,5,CekOK,admin,2026-08-27\n"
            .',2,A,CUST01,ModelX,GA241-04750,GA241-04750-FG,JOB01,Part Name X,BoxA,10,'
            ."Proc1,Line1,LC1,Rack1,Cap1,Jig1,50,5,100,img1.png,Route1,Remark1,1,2,CPS1,SetA,Point1,Mach1,100,5,CekOK,admin,2026-08-27\n";

        $response = $this->actingAs($this->authorizedUser())
            ->post(route('parts.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('1 baris dilewati', session('status'));
        $this->assertSame(1, Part::count());
    }

    public function test_parts_are_listed_in_the_same_order_as_the_imported_file(): void
    {
        $csv = ",banner\n"
            .$this->header()
            .$this->row(1, 'ZZZ-LAST')
            .$this->row(2, 'AAA-FIRST')
            .$this->row(3, 'MMM-MID');

        $this->actingAs($this->authorizedUser())
            ->post(route('parts.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('import.csv', $csv),
            ]);

        // Alphabetically this would be AAA-FIRST, MMM-MID, ZZZ-LAST — the list must
        // instead follow the row order from the imported file.
        $orderedPartNos = Part::orderByRaw('import_order is null')
            ->orderBy('import_order')
            ->orderBy('part_no')
            ->pluck('part_no')
            ->all();

        $this->assertSame(['ZZZ-LAST', 'AAA-FIRST', 'MMM-MID'], $orderedPartNos);
    }
}
