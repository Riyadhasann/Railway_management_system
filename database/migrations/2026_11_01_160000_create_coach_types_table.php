<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('seat_class', 30); // App\Enums\SeatClass, used by fare rules
            $table->unsignedTinyInteger('layout_rows');
            $table->unsignedTinyInteger('layout_cols');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_type_id')->constrained()->cascadeOnDelete();
            $table->string('seat_no', 10);
            $table->unsignedTinyInteger('row'); // 1-based grid position
            $table->unsignedTinyInteger('col');
            $table->string('berth', 10)->nullable(); // lower / upper for sleeper coaches
            $table->json('flags')->nullable();       // e.g. ["women", "accessible"]
            $table->timestamps();

            $table->unique(['coach_type_id', 'seat_no']);
            $table->unique(['coach_type_id', 'row', 'col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
        Schema::dropIfExists('coach_types');
    }
};
