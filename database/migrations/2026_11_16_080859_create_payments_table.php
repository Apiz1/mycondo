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
       Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('paid_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->string('method');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->string('proof_path')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
