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
 * What a person does in a team (Frontend Developer, Tester, AI Engineer ...), set by the team's
 * Project Manager or Project Lead on the "Team Mapping" page.
 */
final class Version20261008140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Role of a person within a team';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_users_teams ADD team_role VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_users_teams DROP team_role');
    }
}
