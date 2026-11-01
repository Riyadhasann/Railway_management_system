<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trains', function (Blueprint $table) {
            $table->id();
            $table->string('number', 10)->unique();
            $table->string('name');
            $table->foreignId('train_route_id')->constrained()->restrictOnDelete();
            // ISO weekdays the train departs its origin: 1 = Monday ... 7 = Sunday.
            $table->json('running_days');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trains');
    }
};
