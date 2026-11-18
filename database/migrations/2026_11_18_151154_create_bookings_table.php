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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->string('status')->default('pending');
            $table->string('purpose')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'start_at']);
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_valid_range CHECK (end_at > start_at)');

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
            EXCLUDE USING gist (facility_id WITH =, tsrange(start_at, end_at) WITH &&)
            WHERE (status IN ('pending', 'approved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
