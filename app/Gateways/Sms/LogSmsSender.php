<?php

namespace App\Gateways\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Prototype SMS driver: writes messages to the log instead of sending them.
 * The UI shows OTP codes on screen, so nothing needs to reach a phone.
 */
class LogSmsSender implements SmsSender
{
    /** @var list<array{phone: string, message: string}> */
    private array $sent = [];

    public function send(string $phone, string $message): void
    {
        $this->sent[] = ['phone' => $phone, 'message' => $message];

        Log::info('SMS (not sent, prototype)', ['phone' => $phone, 'message' => $message]);
    }

    /**
     * @return list<array{phone: string, message: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
