<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear the DBAL 3 type comments, which DBAL 4 no longer uses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN strava_token.updated_at IS NULL');
        $this->addSql('COMMENT ON COLUMN strava_weekly_stat.week_start IS NULL');
        $this->addSql('COMMENT ON COLUMN strava_weekly_stat.updated_at IS NULL');
        $this->addSql('COMMENT ON COLUMN strava_weekly_plan.week_start IS NULL');
        $this->addSql('COMMENT ON COLUMN strava_weekly_plan.updated_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('COMMENT ON COLUMN strava_token.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN strava_weekly_stat.week_start IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN strava_weekly_stat.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN strava_weekly_plan.week_start IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN strava_weekly_plan.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }
}
