<?php

declare(strict_types=1);

namespace App\Exception\Strava;

use Exception;

/** A weekly plan payload that can't be stored (bad week, day, sport or distance). */
class InvalidPlan extends Exception
{
}
