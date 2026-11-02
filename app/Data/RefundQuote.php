<?php

namespace App\Data;

use App\Models\RefundRule;

/**
 * What cancelling a booking now would return to the passenger.
 */
final readonly class RefundQuote
{
    public function __construct(
        /** Hours from now until the train leaves the boarding station (0 if it has left). */
        public float $hoursBefore,
        /** Money paid and not yet refunded. */
        public int $paidPoisha,
        public int $percent,
        public int $feePoisha,
        public int $amountPoisha,
        public ?RefundRule $rule = null,
    ) {}

    public function isRefundable(): bool
    {
        return $this->amountPoisha > 0;
    }
}
