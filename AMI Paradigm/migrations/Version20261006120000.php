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
 * Standard tasks of an activity (project > phase > activity > task).
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the table for the standard tasks of an activity';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_activity_tasks')) {
            $this->preventEmptyMigrationWarning();

            return;
        }

        $table = $schema->createTable('kimai2_activity_tasks');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('name', Types::STRING, ['length' => 150, 'notnull' => true]);
        $table->addColumn('activity_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name']);
        $table->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_activity_tasks')) {
            $schema->dropTable('kimai2_activity_tasks');
        }
    }
}
