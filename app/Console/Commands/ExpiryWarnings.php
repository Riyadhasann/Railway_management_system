<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Mail\ExpiryWarningsMail;
use App\Models\User;
use App\Services\CrewService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('crew:expiry-warnings')]
#[Description('Email crew controllers about driving licences and loco maintenance coming due')]
class ExpiryWarnings extends Command
{
    public function handle(CrewService $crew): int
    {
        $warnings = $crew->expiryWarnings();
        $count = $warnings['licences']->count() + $warnings['locos']->count();

        $this->info("{$warnings['licences']->count()} licence(s) and {$warnings['locos']->count()} loco(s) need attention.");

        if ($count === 0) {
            return self::SUCCESS;
        }

        $recipients = User::permission(Permission::ManageAssignments->value)->pluck('email');

        foreach ($recipients as $email) {
            Mail::to($email)->send(new ExpiryWarningsMail($warnings['licences'], $warnings['locos']));
        }

        $this->info("Sent to {$recipients->count()} crew controller(s).");

        return self::SUCCESS;
    }
}
