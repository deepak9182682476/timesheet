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
use Doctrine\DBAL\Types\Types;

/**
 * Phases (project > phase > activity > task) and an optional activity on tasks.
 */
final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the phases tables and the activity of a task';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_phases')) {
            $table = $schema->createTable('kimai2_phases');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('name', Types::STRING, ['length' => 100, 'notnull' => true]);
            $table->addColumn('project_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['name']);
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        if (!$schema->hasTable('kimai2_phase_activities')) {
            $table = $schema->createTable('kimai2_phase_activities');
            $table->addColumn('phase_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('activity_id', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['phase_id', 'activity_id']);
            $table->addForeignKeyConstraint('kimai2_phases', ['phase_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        $tasks = $schema->getTable('kimai2_tasks');
        if (!$tasks->hasColumn('activity_id')) {
            $tasks->addColumn('activity_id', Types::INTEGER, ['notnull' => false]);
            $tasks->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_phase_activities', 'kimai2_phases'] as $name) {
            if ($schema->hasTable($name)) {
                $schema->dropTable($name);
            }
        }
    }
}
