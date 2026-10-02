<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A team operation that breaks a business rule (e.g. removing the owner).
 * The message is safe to show to the user.
 */
class TeamActionException extends RuntimeException {}
