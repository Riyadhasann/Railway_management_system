<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A TTE marks a passenger who did not board.
        Schema::table('passengers', function (Blueprint $table) {
            $table->timestamp('no_show_at')->nullable()->after('fare_poisha');
            $table->foreignId('no_show_by')->nullable()->after('no_show_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('passengers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('no_show_by');
            $table->dropColumn('no_show_at');
        });
    }
};
