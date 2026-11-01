<?php

namespace App\Enums;

/**
 * Permission names stored by spatie/laravel-permission.
 * See railway-erp-architecture.md §2.8 for the role matrix.
 */
enum Permission: string
{
    case ManageUsers = 'users.manage';
    case ViewAuditLog = 'audit.view';
    case ManageFareRules = 'fares.manage';
    case ViewFareRules = 'fares.view';
    case ManageNetwork = 'network.manage';
    case ViewNetwork = 'network.view';
    case CounterBooking = 'bookings.counter';
    case RecordActualTimes = 'operations.actual_times';
    case CheckTickets = 'tickets.check';
    case ManageAssignments = 'assignments.manage';
    case ViewAssignments = 'assignments.view';
    case ManagePayments = 'payments.manage';
    case ViewPayments = 'payments.view';
    case ViewReports = 'reports.view';
    case BookOwnTickets = 'bookings.own';

    /**
     * Every permission except passenger self-service.
     *
     * @return list<self>
     */
    public static function staffPermissions(): array
    {
        $permissions = [];

        foreach (self::cases() as $permission) {
            if ($permission !== self::BookOwnTickets) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }
}
