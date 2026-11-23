<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the activities of each week on strava_weekly_stat, so the stats page renders from the database';
    }

    public function up(Schema $schema): void
    {
        // Default only fills existing rows; the application always sets the value.
        $this->addSql('ALTER TABLE strava_weekly_stat ADD activities JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE strava_weekly_stat ALTER activities DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE strava_weekly_stat DROP activities');
    }
}
