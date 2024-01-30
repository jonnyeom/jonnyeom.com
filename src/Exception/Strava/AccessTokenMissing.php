<?php

declare(strict_types=1);

namespace App\Exception\Strava;

use Exception;

/**
 * Exception thrown if we are not authenticated with an Access Token.
 */
class AccessTokenMissing extends Exception
{
}
