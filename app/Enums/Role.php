<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Role: string implements HasLabel
{
    case Admin = 'admin';
    case Planner = 'planner';
    case CounterClerk = 'counter_clerk';
    case StationMaster = 'station_master';
    case Tte = 'tte';
    case CrewController = 'crew_controller';
    case Finance = 'finance';
    case Manager = 'manager';
    case Passenger = 'passenger';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Planner => 'Operations planner',
            self::CounterClerk => 'Counter clerk',
            self::StationMaster => 'Station master',
            self::Tte => 'TTE',
            self::CrewController => 'Crew controller',
            self::Finance => 'Finance',
            self::Manager => 'Manager',
            self::Passenger => 'Passenger',
        };
    }

    /**
     * Staff roles may use the back-office panel; passengers use the portal only.
     */
    public function isStaff(): bool
    {
        return $this !== self::Passenger;
    }

    /**
     * @return list<self>
     */
    public static function staff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->isStaff()));
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::staffPermissions(),
            self::Planner => [
                Permission::ManageNetwork,
                Permission::ViewNetwork,
            ],
            self::CounterClerk => [
                Permission::CounterBooking,
                Permission::ViewNetwork,
            ],
            self::StationMaster => [
                Permission::RecordActualTimes,
                Permission::ViewNetwork,
            ],
            self::Tte => [
                Permission::CheckTickets,
            ],
            self::CrewController => [
                Permission::ManageAssignments,
                Permission::ViewAssignments,
                Permission::ViewNetwork,
            ],
            self::Finance => [
                Permission::ManagePayments,
                Permission::ViewPayments,
                Permission::ViewFareRules,
                Permission::ViewReports,
            ],
            self::Manager => [
                Permission::ViewFareRules,
                Permission::ViewNetwork,
                Permission::ViewAssignments,
                Permission::ViewPayments,
                Permission::ViewReports,
            ],
            self::Passenger => [
                Permission::BookOwnTickets,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
