<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            // Order along the route (1 = origin). Not unique, so the editor can reorder rows safely.
            $table->unsignedSmallInteger('sequence');
            $table->unsignedInteger('distance_km');
            $table->timestamps();

            $table->unique(['train_route_id', 'station_id']);
            $table->index(['train_route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
