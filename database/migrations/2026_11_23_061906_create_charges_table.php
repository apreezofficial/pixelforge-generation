<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One charge per generation, enforced by the unique index. Charges are
     * the ledger the admin report reads; a generation can never be billed twice.
     */
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_nano');
            $table->unsignedBigInteger('provider_cost_nano');
            $table->timestamp('charged_at');
            $table->timestamps();

            $table->index(['user_id', 'charged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
