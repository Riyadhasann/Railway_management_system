<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Booking;
use App\Models\TrainRun;
use App\Models\User;
use App\Services\DemoDataService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use UnitEnum;

/**
 * Admin-only: put the demo back to a known state. Not available in production.
 */
class DemoData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Demo data';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.demo-data';

    public static function canAccess(): bool
    {
        return ! app()->isProduction() && (auth()->user()?->hasRole(Role::Admin->value) ?? false);
    }

    public function getTitle(): string
    {
        return 'Demo data';
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'Users' => User::count(),
            'Train runs' => TrainRun::count(),
            'Bookings' => Booking::count(),
            'Bookings on past runs' => Booking::whereHas('trainRun', fn ($q) => $q->whereDate('journey_date', '<', today()))->count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label('Reset demo data')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Reset all demo data?')
                ->modalDescription('Everything is deleted, including bookings, users and changes made by testers, and the demo data is loaded again with a month of booking history. Demo account passwords stay the same. It takes a minute or two and you will need to log in again.')
                ->modalSubmitActionLabel('Yes, reset everything')
                ->action(fn (DemoDataService $demo) => $this->resetDemo($demo)),
        ];
    }

    private function resetDemo(DemoDataService $demo): void
    {
        abort_unless(static::canAccess(), 403);
        set_time_limit(300);

        try {
            $demo->reset();
        } catch (RuntimeException $e) {
            Notification::make()->title('Reset failed')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        // The sessions table was emptied with everything else; start clean.
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        Notification::make()->title('Demo data reset')->body('Log in again with your usual demo password.')->success()->persistent()->send();

        $this->redirect(filament()->getLoginUrl());
    }
}
