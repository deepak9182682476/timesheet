<?php

declare(strict_types=1);

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
 * "Comp off" is renamed "Comp-off Leave" everywhere: the leave type of existing requests,
 * the activity and task it is logged on, and the entries already in timesheets.
 */
final class Version20261009170000 extends AbstractMigration
{
    private const NEW_NAME = 'Comp-off Leave';

    public function getDescription(): string
    {
        return 'Comp off renamed Comp-off Leave';
    }

    public function up(Schema $schema): void
    {
        $db = $this->connection;

        $db->executeStatement('UPDATE kimai2_team_events SET title = ? WHERE type = ? AND title = ?', [self::NEW_NAME, 'leave', 'Comp off']);

        $projectId = $db->fetchOne('SELECT id FROM kimai2_projects WHERE name = ?', ['Non-Project Activities']);
        if ($projectId !== false && $db->fetchOne('SELECT id FROM kimai2_activities WHERE name = ? AND project_id = ?', [self::NEW_NAME, $projectId]) === false) {
            $db->executeStatement('UPDATE kimai2_activities SET name = ? WHERE name = ? AND project_id = ?', [self::NEW_NAME, 'Comp Off', $projectId]);
        }

        if ($db->fetchOne('SELECT id FROM kimai2_activity_tasks WHERE name = ?', [self::NEW_NAME]) === false) {
            $db->executeStatement('UPDATE kimai2_activity_tasks SET name = ? WHERE name = ?', [self::NEW_NAME, 'Comp Off']);
        }

        $db->executeStatement('UPDATE kimai2_timesheet_meta SET value = ? WHERE name = ? AND value = ?', [self::NEW_NAME, 'task', 'Comp Off']);
        $db->executeStatement(
            'UPDATE kimai2_timesheet SET description = CONCAT(?, SUBSTRING(description, 9)) WHERE description LIKE ?',
            [self::NEW_NAME, 'Comp off (%']
        );
    }

    public function down(Schema $schema): void
    {
    }
}
