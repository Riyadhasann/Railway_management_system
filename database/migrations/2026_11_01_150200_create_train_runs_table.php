<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('train_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('train_id')->constrained()->restrictOnDelete();
            $table->date('journey_date'); // departure date from the origin
            $table->string('status', 20)->default('scheduled');
            $table->unsignedSmallInteger('delay_minutes')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['train_id', 'journey_date']);
            $table->index(['journey_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('train_runs');
    }
};
