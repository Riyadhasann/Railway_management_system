<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment callback that is missing fields or whose signature does not match.
 */
class InvalidCallbackException extends RuntimeException {}
