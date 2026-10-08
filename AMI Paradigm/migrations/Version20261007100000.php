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
 * Project models (Agile, Waterfall, Pre-sales): the items people book time on
 * (Epic > Feature > User Story > Activity > Task and the like) and who each item is assigned to.
 */
final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the work item tables for the project models';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_work_items')) {
            $table = $schema->createTable('kimai2_work_items');
            $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('project_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('level', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('name', Types::STRING, ['length' => 150, 'notnull' => true]);
            $table->addColumn('activity_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_by_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['project_id']);
            $table->addIndex(['parent_id']);
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_work_items', ['parent_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('kimai2_users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_work_item_users')) {
            $table = $schema->createTable('kimai2_work_item_users');
            $table->addColumn('work_item_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('user_id', Types::INTEGER, ['notnull' => true]);
            $table->setPrimaryKey(['work_item_id', 'user_id']);
            $table->addForeignKeyConstraint('kimai2_work_items', ['work_item_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_work_item_users', 'kimai2_work_items'] as $name) {
            if ($schema->hasTable($name)) {
                $schema->dropTable($name);
            }
        }
    }
}
