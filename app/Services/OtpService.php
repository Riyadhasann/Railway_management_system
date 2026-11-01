<?php

namespace App\Services;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * One-time codes to confirm a contact phone number. The prototype SMS driver only
 * logs messages, so the caller shows the returned code on screen.
 */
class OtpService
{
    public const int TTL_MINUTES = 10;

    public const int MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Send a new 6-digit code for this purpose (e.g. "booking:12") and phone; returns the code.
     */
    public function send(string $purpose, string $phone): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($purpose), [
            'phone' => $phone,
            'hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addMinutes(self::TTL_MINUTES));

        $this->sms->send($phone, "Your Railway ERP code is {$code}. It expires in ".self::TTL_MINUTES.' minutes.');

        return $code;
    }

    /**
     * Whether the code is right for this purpose and phone. A code works once,
     * and is thrown away after too many wrong tries.
     */
    public function verify(string $purpose, string $phone, string $code): bool
    {
        $key = $this->key($purpose);
        /** @var array{phone: string, hash: string, attempts: int}|null $entry */
        $entry = Cache::get($key);

        if ($entry === null || $entry['phone'] !== $phone) {
            return false;
        }

        if (Hash::check(trim($code), $entry['hash'])) {
            Cache::forget($key);

            return true;
        }

        $entry['attempts']++;
        $entry['attempts'] >= self::MAX_ATTEMPTS
            ? Cache::forget($key)
            : Cache::put($key, $entry, now()->addMinutes(self::TTL_MINUTES));

        return false;
    }

    private function key(string $purpose): string
    {
        return "otp:{$purpose}";
    }
}
