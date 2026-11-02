<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Train crew: drivers, assistant drivers, guards and TTEs.
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('staff_no', 20)->unique();
            $table->string('name');
            $table->string('designation', 20); // App\Enums\StaffDesignation
            $table->string('phone', 20)->nullable();
            $table->foreignId('home_station_id')->nullable()->constrained('stations')->nullOnDelete();
            // Driving licence; required for drivers and assistant drivers.
            $table->string('licence_no', 30)->nullable();
            $table->date('licence_expires_on')->nullable();
            // The login account, e.g. a TTE who uses the scanner.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['designation', 'is_active']);
            $table->index('licence_expires_on'); // expiry warnings
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
