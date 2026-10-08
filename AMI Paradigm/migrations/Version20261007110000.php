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
 * Every project that exists already becomes an Agile project (Epic > Feature > User Story > Activity > Task).
 * An administrator can switch a project to Waterfall on its edit page. The two built-in projects
 * "Non-Project Activities" and "Pre-Sales" have no model to choose.
 */
final class Version20261007110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set the model of the existing projects to Agile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO kimai2_projects_meta (project_id, name, value, visible)
             SELECT p.id, 'model', 'agile', 1
             FROM kimai2_projects p
             WHERE p.name NOT IN ('Non-Project Activities', 'Pre-Sales')
               AND NOT EXISTS (SELECT 1 FROM kimai2_projects_meta m WHERE m.project_id = p.id AND m.name = 'model')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM kimai2_projects_meta WHERE name = 'model'");
    }
}
