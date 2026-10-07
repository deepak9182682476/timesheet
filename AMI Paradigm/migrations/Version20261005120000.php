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
 * Tasks that a manager or lead assigns to a person.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the tasks table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_tasks')) {
            return;
        }

        $table = $schema->createTable('kimai2_tasks');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('title', Types::STRING, ['length' => 200, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('project_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('assignee_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('created_by_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('due_date', Types::DATE_MUTABLE, ['notnull' => false]);
        $table->addColumn('estimate', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->addColumn('completed_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['status']);
        $table->addIndex(['assignee_id']);
        $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('kimai2_users', ['assignee_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('kimai2_users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_tasks')) {
            $schema->dropTable('kimai2_tasks');
        }
    }
}
