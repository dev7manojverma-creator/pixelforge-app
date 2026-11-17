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

            // Pixelforge's id (gen_…). Null until Pixelforge has accepted the request.
            $table->string('provider_id')->nullable()->unique();
            // Sent as the Idempotency-Key header so a retried submit never creates two generations.
            $table->uuid('idempotency_key')->unique();

            $table->string('status', 20)->index();
            $table->text('prompt');
            $table->string('size', 20);
            $table->string('quality', 20);
            $table->json('reference_images');

            // Rates (USD per 1M tokens) this generation is billed at.
            $table->json('rates');

            // What we told the user before they submitted, in nanodollars.
            $table->bigInteger('estimated_charge_nano');
            $table->bigInteger('max_charge_nano');

            // Known once the generation succeeded.
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();
            $table->bigInteger('charge_nano')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            // Last time we asked Pixelforge for this generation's state (polling fallback).
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
