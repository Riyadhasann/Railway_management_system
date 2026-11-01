<?php

use App\Contracts\NidVerifier;
use App\Contracts\SmsSender;
use App\Gateways\Nid\FormatNidVerifier;
use App\Gateways\Sms\LogSmsSender;
use Tests\TestCase;

uses(TestCase::class);

it('binds the prototype drivers to the contracts', function () {
    expect(app(SmsSender::class))->toBeInstanceOf(LogSmsSender::class)
        ->and(app(NidVerifier::class))->toBeInstanceOf(FormatNidVerifier::class);
});

it('accepts 10, 13 and 17 digit NIDs', function (string $nid) {
    expect((new FormatNidVerifier)->verify($nid))->toBeTrue();
})->with([
    '10 digits' => '1234567890',
    '13 digits' => '1234567890123',
    '17 digits' => '12345678901234567',
    'with spaces' => '123 456 7890',
]);

it('rejects malformed NIDs', function (string $nid) {
    expect((new FormatNidVerifier)->verify($nid))->toBeFalse();
})->with([
    'too short' => '12345',
    '11 digits' => '12345678901',
    'letters' => '12345abcde',
    'empty' => '',
]);

it('records SMS messages instead of sending them', function () {
    $sms = new LogSmsSender;

    $sms->send('01700000000', 'Your OTP is 123456');

    expect($sms->sent())->toBe([['phone' => '01700000000', 'message' => 'Your OTP is 123456']]);
});
