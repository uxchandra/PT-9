<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->renameColumn('name', 'part_no');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('level')->nullable()->after('part_no');
            $table->string('customer_code')->nullable()->after('level');
            $table->string('model')->nullable()->after('customer_code');
            $table->string('job_no')->nullable()->after('model');
            $table->string('part_name')->nullable()->after('job_no');
            $table->string('type_box')->nullable()->after('part_name');
            $table->string('qty_kbn')->nullable()->after('type_box');
            $table->string('process')->nullable()->after('qty_kbn');
            $table->string('line')->nullable()->after('process');
            $table->string('line_code')->nullable()->after('line');
            $table->string('rack_no')->nullable()->after('line_code');
            $table->string('cap_rack')->nullable()->after('rack_no');
            $table->string('jig_no')->nullable()->after('cap_rack');
            $table->string('qty_lot')->nullable()->after('jig_no');
            $table->string('stock_min')->nullable()->after('qty_lot');
            $table->string('stock_max')->nullable()->after('stock_min');
            $table->string('last_routing')->nullable()->after('stock_max');
            $table->string('remark')->nullable()->after('last_routing');
            $table->string('lt_pull')->nullable()->after('remark');
            $table->string('lt_prod')->nullable()->after('lt_pull');
            $table->string('code_partset')->nullable()->after('lt_prod');
            $table->string('set_label')->nullable()->after('code_partset');
            $table->string('prod_point')->nullable()->after('set_label');
            $table->string('cat_machine')->nullable()->after('prod_point');
            $table->string('spm')->nullable()->after('cat_machine');
            $table->string('dandory')->nullable()->after('spm');
            $table->string('cek_startfinish')->nullable()->after('dandory');
            $table->string('update_by')->nullable()->after('cek_startfinish');
            $table->string('update_time')->nullable()->after('update_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->dropColumn([
                'level', 'customer_code', 'model', 'job_no', 'part_name', 'type_box',
                'qty_kbn', 'process', 'line', 'line_code', 'rack_no', 'cap_rack',
                'jig_no', 'qty_lot', 'stock_min', 'stock_max', 'last_routing', 'remark',
                'lt_pull', 'lt_prod', 'code_partset', 'set_label', 'prod_point',
                'cat_machine', 'spm', 'dandory', 'cek_startfinish', 'update_by', 'update_time',
            ]);
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->renameColumn('part_no', 'name');
        });
    }
};
