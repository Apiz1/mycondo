<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX residencies_one_active_per_user_unit
            ON residencies (user_id, unit_id) WHERE moved_out_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX residencies_one_active_per_user_unit');
    }
};
