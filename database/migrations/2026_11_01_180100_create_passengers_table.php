<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A traveller on a booking, sitting in one seat.
        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_booking_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('age');
            $table->string('gender', 10); // App\Enums\Gender
            $table->string('nid', 20)->nullable();
            $table->foreignId('concession_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('fare_poisha');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passengers');
    }
};
