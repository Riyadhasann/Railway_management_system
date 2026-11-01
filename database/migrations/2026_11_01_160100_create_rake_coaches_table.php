<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A train's coach composition, in order from the engine.
        Schema::create('rake_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->constrained()->cascadeOnDelete();
            $table->string('coach_no', 10); // e.g. KA, KHA, GA
            $table->foreignId('coach_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // Seats kept for counter sales; the rest are sold online. Enforced in booking.
            $table->unsignedSmallInteger('counter_quota')->default(0);
            $table->timestamps();

            $table->unique(['train_id', 'coach_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rake_coaches');
    }
};
