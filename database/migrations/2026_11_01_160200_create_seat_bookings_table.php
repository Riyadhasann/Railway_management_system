<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seat inventory per train run. A physical seat is (train_run_id, coach_no, seat_id);
        // a row occupies the segment from_seq -> to_seq (route stop sequences).
        Schema::create('seat_bookings', function (Blueprint $table) {
            $table->id();
            // Bookings arrive with the booking engine; admin blocks have no booking.
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->foreignId('train_run_id')->constrained()->restrictOnDelete();
            $table->string('coach_no', 10);
            $table->foreignId('seat_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('from_seq');
            $table->unsignedSmallInteger('to_seq');
            $table->string('status', 20); // App\Enums\SeatBookingStatus
            $table->timestamp('hold_until')->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['train_run_id', 'coach_no', 'seat_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_bookings');
    }
};
