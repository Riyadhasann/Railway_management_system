<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locomotives', function (Blueprint $table) {
            $table->id();
            $table->string('loco_no', 20)->unique();
            $table->string('model', 50); // e.g. "MEI 3000 class"
            $table->string('traction', 10)->default('diesel');
            $table->string('home_shed', 50)->nullable();
            $table->string('status', 20); // App\Enums\LocoStatus
            $table->date('last_maintenance_on')->nullable();
            $table->date('next_maintenance_due')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('next_maintenance_due'); // expiry warnings
        });

        // Workshop visits. An open one (completed_on null) keeps the loco under repair.
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('locomotive_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // App\Enums\MaintenanceType
            $table->date('started_on');
            $table->date('completed_on')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['locomotive_id', 'completed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenances');
        Schema::dropIfExists('locomotives');
    }
};
