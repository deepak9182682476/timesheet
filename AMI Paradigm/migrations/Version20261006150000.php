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
 * - tasks remember whether the assignee has seen them (for the notification bell)
 * - a leave request can carry the reason it was rejected for
 */
final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the seen flag of a task and the reason of a rejected leave';
    }

    public function up(Schema $schema): void
    {
        $tasks = $schema->getTable('kimai2_tasks');
        if (!$tasks->hasColumn('assignee_seen')) {
            // tasks that exist already count as seen: only new assignments ring the bell
            $tasks->addColumn('assignee_seen', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        }

        $events = $schema->getTable('kimai2_team_events');
        if (!$events->hasColumn('decision_comment')) {
            $events->addColumn('decision_comment', Types::TEXT, ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $tasks = $schema->getTable('kimai2_tasks');
        if ($tasks->hasColumn('assignee_seen')) {
            $tasks->dropColumn('assignee_seen');
        }

        $events = $schema->getTable('kimai2_team_events');
        if ($events->hasColumn('decision_comment')) {
            $events->dropColumn('decision_comment');
        }
    }
}
