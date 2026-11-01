<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A booking request that breaks a business rule (cancelled or departed run,
 * too many seats, counter-only seats online...). The message is safe to show users.
 */
class BookingRefusedException extends RuntimeException {}
