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
       Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('checked_in_by')->nullable()->constrained('users');
            $table->string('name');
            $table->string('vehicle_plate', 20)->nullable();
            $table->date('visit_date');
            $table->string('code', 12)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['visit_date', 'status']);
            $table->index(['unit_id', 'visit_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
