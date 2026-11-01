<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One test account per role: <role>@railway-erp.test.
 * Passwords come from DEMO_PASSWORD, or are generated and saved to
 * storage/app/private/demo-credentials.txt (outside version control).
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $fixed = config('railway.demo_password');
        $domain = config('railway.demo_email_domain');
        $rows = [];

        foreach (Role::cases() as $role) {
            $password = is_string($fixed) && $fixed !== '' ? $fixed : Str::password(12, symbols: false);
            $email = "{$role->value}@{$domain}";

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => "Demo {$role->getLabel()}",
                    'password' => $password,
                ],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role->value]);

            $rows[] = [$role->getLabel(), $email, $password];
        }

        $lines = array_map(fn (array $r) => implode("\t", $r), $rows);
        Storage::disk('local')->put(
            'demo-credentials.txt',
            'Railway ERP demo accounts (generated '.now()->toDateTimeString().")\n\n".implode("\n", $lines)."\n",
        );

        $this->command?->table(['Role', 'Email', 'Password'], $rows);
        $this->command?->info('Saved to storage/app/private/demo-credentials.txt. Share privately with testers.');
    }
}
