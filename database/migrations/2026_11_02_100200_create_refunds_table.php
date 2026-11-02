<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money returned against one payment.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('reason', 30); // App\Enums\RefundReason
            $table->unsignedInteger('amount_poisha');
            $table->unsignedInteger('fee_poisha')->default(0);
            $table->unsignedTinyInteger('percent');
            // Hours between cancelling and departure from the boarding station.
            $table->decimal('hours_before', 8, 2)->nullable();
            $table->string('status', 20); // App\Enums\RefundStatus
            $table->string('txn_id', 64)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
