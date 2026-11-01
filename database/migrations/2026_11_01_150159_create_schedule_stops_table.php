<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_stop_id')->constrained()->cascadeOnDelete();
            $table->time('arrival_time')->nullable();    // null at the origin
            $table->time('departure_time')->nullable();  // null at the terminus
            // Days after the journey date (0 = same day, 1 = next day for overnight trains).
            $table->unsignedTinyInteger('day_offset')->default(0);
            $table->timestamps();

            $table->unique(['train_id', 'route_stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_stops');
    }
};
