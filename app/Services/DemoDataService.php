<?php

namespace App\Services;

use Database\Seeders\PastBookingsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Put the demo back to a known state for testers: empty database, demo network,
 * accounts and a month of booking history. Demo account passwords survive the reset.
 */
class DemoDataService
{
    public const string CREDENTIALS_FILE = 'demo-credentials.txt';

    /**
     * @throws RuntimeException in production, where the data is real
     */
    public function reset(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data can not be reset in production.');
        }

        // Testers keep their logins: remember the demo accounts' password hashes (and
        // the credentials file that lists them) and put them back after reseeding.
        $domain = (string) config('railway.demo_email_domain');
        $passwords = DB::table('users')->where('email', 'like', "%@{$domain}")->pluck('password', 'email')->all();
        $credentials = Storage::disk('local')->get(self::CREDENTIALS_FILE);

        $this->artisan('migrate:fresh', ['--force' => true, '--seed' => true]);
        $this->artisan('db:seed', ['--class' => PastBookingsSeeder::class, '--force' => true]);

        foreach ($passwords as $email => $hash) {
            DB::table('users')->where('email', $email)->update(['password' => $hash]);
        }

        if ($passwords !== [] && $credentials !== null) {
            Storage::disk('local')->put(self::CREDENTIALS_FILE, $credentials);
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisan(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException("{$command} failed: ".trim(Artisan::output()));
        }
    }
}
