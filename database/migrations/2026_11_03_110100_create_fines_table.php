<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash penalty collected on board by a TTE.
        Schema::create('fines', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 20)->unique();
            $table->foreignId('train_run_id')->constrained()->restrictOnDelete();
            // The ticket the passenger showed, if any (e.g. travelling beyond the destination).
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tte_id')->constrained('users')->restrictOnDelete();
            $table->string('passenger_name');
            $table->string('reason', 30); // App\Enums\FineReason
            $table->unsignedInteger('amount_poisha');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['train_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fines');
    }
};
