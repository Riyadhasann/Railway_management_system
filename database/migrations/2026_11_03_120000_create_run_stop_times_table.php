<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Actual arrival and departure at one stop of one run, typed in by the station master.
        Schema::create('run_stop_times', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_stop_id')->constrained()->restrictOnDelete();
            $table->timestamp('actual_arrival')->nullable();
            $table->timestamp('actual_departure')->nullable();
            // Minutes late at this stop (0 if on time or early).
            $table->unsignedSmallInteger('delay_minutes')->default(0);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['train_run_id', 'schedule_stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_stop_times');
    }
};
