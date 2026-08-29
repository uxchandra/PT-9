<?php

use App\Models\PatternGroupItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix: total_kanban was being entered manually and is wrong for
     * existing rows. It's derived data — lot divided by the part's Qty Kbn
     * (Part List), rounded up — so every existing pattern_group_items row is
     * recalculated here to match.
     */
    public function up(): void
    {
        $rows = DB::table('pattern_group_items')
            ->join('parts', 'parts.id', '=', 'pattern_group_items.part_id')
            ->select('pattern_group_items.id', 'pattern_group_items.lot', 'parts.qty_kbn')
            ->orderBy('pattern_group_items.id')
            ->get();

        foreach ($rows as $row) {
            $totalKanban = PatternGroupItem::calculateTotalKanban($row->lot, $row->qty_kbn);

            DB::table('pattern_group_items')->where('id', $row->id)->update(['total_kanban' => $totalKanban]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * The original manually-entered values aren't recoverable, so this is a
     * no-op — total_kanban stays derived.
     */
    public function down(): void
    {
        //
    }
};
