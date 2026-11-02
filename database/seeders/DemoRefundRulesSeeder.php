<?php

namespace Database\Seeders;

use App\Models\RefundRule;
use Illuminate\Database\Seeder;

/**
 * Demo refund rules by hours before departure. Sample numbers for testing only.
 * Less than 12 hours before departure: no refund. Safe to re-run.
 */
class DemoRefundRulesSeeder extends Seeder
{
    /** hours_before => [percent, fee in poisha] */
    private const array RULES = [
        48 => [90, 2000],
        24 => [75, 2000],
        12 => [50, 2000],
    ];

    public function run(): void
    {
        foreach (self::RULES as $hours => [$percent, $fee]) {
            RefundRule::firstOrCreate(['hours_before' => $hours], ['percent' => $percent, 'fee_poisha' => $fee]);
        }
    }
}
