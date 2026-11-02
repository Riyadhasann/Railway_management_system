<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who works on a train run. Written only by CrewService.
        Schema::create('crew_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('duty', 20); // App\Enums\StaffDesignation at the time of assignment
            // Copied from the run, so "one run per person per day" is a unique index.
            $table->date('journey_date');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['staff_id', 'journey_date']);
            $table->index('train_run_id');
        });

        // Which locomotive hauls a train run: one per run, one run per loco per day.
        Schema::create('loco_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('locomotive_id')->constrained()->restrictOnDelete();
            $table->date('journey_date');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locomotive_id', 'journey_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loco_assignments');
        Schema::dropIfExists('crew_assignments');
    }
};
