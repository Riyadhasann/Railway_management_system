<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to pay for a booking: online through the gateway, or cash at the counter.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();
            $table->string('method', 10); // App\Enums\PaymentMethod
            // Our reference, sent to the gateway when checkout starts.
            $table->string('reference', 40)->unique();
            // The gateway's transaction id. Unique, so a duplicate callback can't be applied twice.
            $table->string('txn_id', 64)->nullable()->unique();
            $table->unsignedInteger('amount_poisha');
            $table->unsignedInteger('refunded_poisha')->default(0);
            $table->string('status', 20); // App\Enums\PaymentStatus
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            // The clerk who took a cash payment.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index(['status', 'paid_at']); // revenue reports
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
