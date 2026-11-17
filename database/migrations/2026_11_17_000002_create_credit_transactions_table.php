<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // purchase | charge
            // Signed: purchases are positive, charges negative.
            $table->bigInteger('amount_nano');
            $table->bigInteger('balance_after_nano');
            // A generation can be charged at most once: enforced by the database.
            $table->foreignId('generation_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
