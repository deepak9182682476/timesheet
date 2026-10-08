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
 * A person can have several roles in a team ("Frontend Developer, Tester / QA"): room for more than one.
 */
final class Version20261008150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Several roles of a person within a team';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_users_teams MODIFY team_role VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_users_teams MODIFY team_role VARCHAR(100) DEFAULT NULL');
    }
}
