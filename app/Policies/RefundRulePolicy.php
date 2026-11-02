<?php

namespace App\Policies;

/**
 * Refund rules share the fare rules' access: admin edits, finance and manager view.
 */
class RefundRulePolicy extends FarePolicy {}
