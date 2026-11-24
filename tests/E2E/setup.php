<?php

declare(strict_types=1);

/**
 * Prepares the database for the Playwright tests (see e2e/setup.js): Strava's login can't run there,
 * so this stores a session that is already connected as the allowed athlete.
 */

use App\Service\Strava\AthleteGuard;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$databaseUrl  = (string) getenv('DATABASE_URL');
$athleteGuard = new AthleteGuard((string) getenv('STRAVA_ATHLETE_ID'));

// No cookies or cache headers: there is no HTTP response here. Strict mode would replace our session ID.
$storage = new NativeSessionStorage(
    ['use_cookies' => 0, 'cache_limiter' => '', 'use_strict_mode' => 0],
    new PdoSessionHandler($databaseUrl, ['ttl' => 86400]),
);
$session = new Session($storage);
$session->setId((string) getenv('E2E_SESSION_ID'));
$athleteGuard->connect($session);
$session->save();

$dsnParser  = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
$connection = DriverManager::getConnection($dsnParser->parse($databaseUrl));
$connection->executeStatement(
    'DELETE FROM strava_weekly_plan WHERE athlete_id = ?',
    [$athleteGuard->getAllowedAthleteId()],
);
