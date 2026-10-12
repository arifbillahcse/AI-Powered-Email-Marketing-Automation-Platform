<?php

namespace App\Services\Agency;

use RuntimeException;

/**
 * An agency action that isn't allowed; the message is shown to the user.
 */
class AgencyException extends RuntimeException {}
