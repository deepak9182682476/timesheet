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
 * Events can have a time of day in addition to their dates.
 */
final class Version20261005150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add start and end time to team events';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_team_events');

        if (!$table->hasColumn('start_time')) {
            $table->addColumn('start_time', Types::TIME_MUTABLE, ['notnull' => false]);
        }
        if (!$table->hasColumn('end_time')) {
            $table->addColumn('end_time', Types::TIME_MUTABLE, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_team_events');

        if ($table->hasColumn('start_time')) {
            $table->dropColumn('start_time');
        }
        if ($table->hasColumn('end_time')) {
            $table->dropColumn('end_time');
        }
    }
}
