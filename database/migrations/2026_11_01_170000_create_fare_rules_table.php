<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fare per seat for a class and distance band (km_from..km_to inclusive), in poisha.
        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->string('seat_class', 20); // App\Enums\SeatClass
            $table->unsignedSmallInteger('km_from');
            $table->unsignedSmallInteger('km_to');
            $table->unsignedInteger('fare_poisha');
            $table->timestamps();

            $table->index(['seat_class', 'km_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_rules');
    }
};
