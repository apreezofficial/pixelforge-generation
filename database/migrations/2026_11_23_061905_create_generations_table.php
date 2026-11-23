<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Local key, also sent as Pixelforge's Idempotency-Key so retries never create duplicates.
            $table->uuid('uuid')->unique();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 10);
            $table->json('reference_images');
            // submitting (local, not yet accepted by Pixelforge) | queued | processing | succeeded | failed
            $table->string('status', 20)->default('submitting')->index();
            $table->unsignedBigInteger('estimated_nano');
            $table->unsignedBigInteger('provider_cost_nano')->nullable();
            $table->unsignedBigInteger('charge_nano')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
