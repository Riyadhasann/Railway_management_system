<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date-range filters on the two tables that grow with every sale and every change:
 * Payments ("from / until" on created_at) and the Audit log (same filter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('activity_log', fn (Blueprint $table) => $table->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::table('activity_log', fn (Blueprint $table) => $table->dropIndex(['created_at']));
    }
};
