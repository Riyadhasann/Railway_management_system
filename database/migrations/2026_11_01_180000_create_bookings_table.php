<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One reservation (PNR) on one train run for the segment from_seq -> to_seq.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('pnr', 10)->unique();
            // The passenger account that owns the booking; null for counter walk-ins.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // The clerk who sold a counter booking.
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('train_run_id')->constrained()->restrictOnDelete();
            $table->string('channel', 10); // App\Enums\Channel
            $table->unsignedSmallInteger('from_seq');
            $table->unsignedSmallInteger('to_seq');
            $table->unsignedInteger('total_poisha');
            $table->string('status', 20); // App\Enums\BookingStatus
            $table->timestamp('hold_until')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['train_run_id', 'status']);
            $table->index(['status', 'hold_until']); // holds:release
        });

        // seat_bookings.booking_id existed before bookings did; add its foreign key now.
        Schema::table('seat_bookings', function (Blueprint $table) {
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seat_bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });

        Schema::dropIfExists('bookings');
    }
};
