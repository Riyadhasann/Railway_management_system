<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Symfony\Component\HttpFoundation\Request;

// Both the middleware and Symfony's Request keep the trusted list in static properties.
afterEach(function () {
    TrustProxies::flushState();
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

/**
 * GET /login as if it came through a tunnel on this PC: from 127.0.0.1, forwarded from https.
 */
function viaTunnel(): string
{
    return test()->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'demo.trycloudflare.com', 'X-Forwarded-Port' => '443'])
        ->get('/login')
        ->assertOk()
        ->getContent();
}

// First: once a test has built https:// asset URLs, the asset code caches them for the rest of this PHP process.
it('ignores forwarded headers when no proxy is trusted', function () {
    expect(config('railway.trusted_proxies'))->toBe([])
        ->and(viaTunnel())->not->toContain('demo.trycloudflare.com');
});

it('uses the tunnel https address when the local proxy is trusted', function () {
    config(['railway.trusted_proxies' => ['127.0.0.1', '::1']]);
    (new AppServiceProvider(app()))->boot();

    expect(viaTunnel())->toContain('https://demo.trycloudflare.com/')
        ->not->toContain('http://demo.trycloudflare.com/');
});

it('reads a comma-separated list from the environment', function () {
    putenv('TRUSTED_PROXIES= 127.0.0.1, ::1 ,');
    $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = ' 127.0.0.1, ::1 ,';

    try {
        expect((require config_path('railway.php'))['trusted_proxies'])->toBe(['127.0.0.1', '::1']);
    } finally {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }
});
