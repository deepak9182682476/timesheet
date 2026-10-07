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
 * Leave requests: an event can wait for a manager's decision.
 */
final class Version20261005140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add approval status to team events';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_team_events');

        if (!$table->hasColumn('status')) {
            $table->addColumn('status', Types::STRING, ['length' => 20, 'notnull' => true, 'default' => 'approved']);
        }

        if (!$table->hasColumn('decided_by_id')) {
            $table->addColumn('decided_by_id', Types::INTEGER, ['notnull' => false]);
            $table->addForeignKeyConstraint('kimai2_users', ['decided_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_team_events');

        foreach ($table->getForeignKeys() as $foreignKey) {
            if (\in_array('decided_by_id', $foreignKey->getLocalColumns(), true)) {
                $table->removeForeignKey($foreignKey->getName());
            }
        }
        if ($table->hasColumn('decided_by_id')) {
            $table->dropColumn('decided_by_id');
        }
        if ($table->hasColumn('status')) {
            $table->dropColumn('status');
        }
    }
}
