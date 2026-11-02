<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cancelling at least hours_before departure refunds percent of the fare, less fee_poisha.
        // The rule with the largest hours_before that still applies wins; no rule means no refund.
        Schema::create('refund_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('hours_before')->unique();
            $table->unsignedTinyInteger('percent');
            $table->unsignedInteger('fee_poisha')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_rules');
    }
};
