<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DoctrineMigrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * An event can be for "All my teams": every team of the person who added it.
 */
final class Version20261008120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Events for all teams of the person who added them';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_team_events ADD all_my_teams TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_team_events DROP all_my_teams');
    }
}
