<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every QR scan by a TTE, whatever the result, so disputes and fraud attempts can be traced.
        Schema::create('ticket_scans', function (Blueprint $table) {
            $table->id();
            // Null when the code could not be matched to a booking.
            $table->foreignId('booking_id')->nullable()->constrained()->restrictOnDelete();
            // The run the TTE was checking.
            $table->foreignId('train_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('tte_id')->constrained('users')->restrictOnDelete();
            $table->string('result', 20); // App\Enums\ScanResult
            $table->string('pnr', 10)->nullable(); // as read from the code, for invalid scans
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['booking_id', 'result']); // "already used" check
            $table->index(['train_run_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_scans');
    }
};
