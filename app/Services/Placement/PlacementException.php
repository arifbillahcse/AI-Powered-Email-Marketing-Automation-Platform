<?php

namespace App\Services\Placement;

use RuntimeException;

/**
 * A placement test that can't start; the message is shown to the user.
 */
class PlacementException extends RuntimeException {}
