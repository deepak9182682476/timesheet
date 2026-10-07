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
 * Marks the leave requests whose days were already put into the person's timesheet.
 */
final class Version20261006160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the timesheet marker of a leave request';
    }

    public function up(Schema $schema): void
    {
        $events = $schema->getTable('kimai2_team_events');
        if (!$events->hasColumn('timesheet_synced')) {
            $events->addColumn('timesheet_synced', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $events = $schema->getTable('kimai2_team_events');
        if ($events->hasColumn('timesheet_synced')) {
            $events->dropColumn('timesheet_synced');
        }
    }
}
