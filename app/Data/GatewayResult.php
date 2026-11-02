<?php

namespace App\Data;

/**
 * What a payment gateway reported: for a callback, the outcome of a checkout;
 * for a refund, whether the money was returned.
 */
final readonly class GatewayResult
{
    public const string SUCCESS = 'success';

    public const string FAILED = 'failed';

    /** The passenger left the gateway page without paying. */
    public const string CANCELLED = 'cancelled';

    public function __construct(
        public string $outcome,
        public string $reference,
        public ?string $txnId = null,
        public ?string $message = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === self::SUCCESS;
    }
}
