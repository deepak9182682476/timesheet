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
 * Events such as team outings, holidays and leave, shown on the dashboard.
 */
final class Version20261005130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the team events table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_team_events')) {
            return;
        }

        $table = $schema->createTable('kimai2_team_events');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('title', Types::STRING, ['length' => 150, 'notnull' => true]);
        $table->addColumn('type', Types::STRING, ['length' => 20, 'notnull' => true]);
        $table->addColumn('description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('start_date', Types::DATE_MUTABLE, ['notnull' => true]);
        $table->addColumn('end_date', Types::DATE_MUTABLE, ['notnull' => true]);
        $table->addColumn('user_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('team_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_by_id', Types::INTEGER, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['start_date', 'end_date']);
        $table->addForeignKeyConstraint('kimai2_users', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('kimai2_teams', ['team_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('kimai2_users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_team_events')) {
            $schema->dropTable('kimai2_team_events');
        }
    }
}
