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
 * Leave and comp-off in their own colours: comp-off gets its own activity "Comp Off"
 * (Non-Project Activities > Leave & Time Off), and both activities get a colour, so the
 * calendar, the bar graph and the lists tell them apart from other non-project time.
 * Colours an administrator already set are kept.
 */
final class Version20261009160000 extends AbstractMigration
{
    private const PROJECT = 'Non-Project Activities';
    private const PHASE = 'Leave & Time Off';
    private const LEAVE = 'Leave';
    private const COMP_OFF = 'Comp Off';
    private const LEAVE_COLOR = '#f76707';
    private const COMP_OFF_COLOR = '#ae3ec9';

    public function getDescription(): string
    {
        return 'Own activity and colours for leave and comp-off';
    }

    public function up(Schema $schema): void
    {
        $db = $this->connection;
        $projectId = $db->fetchOne('SELECT id FROM kimai2_projects WHERE name = ?', [self::PROJECT]);
        if ($projectId === false) {
            return;
        }

        $leaveId = $db->fetchOne('SELECT id FROM kimai2_activities WHERE name = ? AND project_id = ?', [self::LEAVE, $projectId]);
        if ($leaveId !== false) {
            $db->executeStatement('UPDATE kimai2_activities SET color = ? WHERE id = ? AND (color IS NULL OR color = \'\')', [self::LEAVE_COLOR, $leaveId]);
        }

        $compOffId = $db->fetchOne('SELECT id FROM kimai2_activities WHERE name = ? AND project_id = ?', [self::COMP_OFF, $projectId]);
        if ($compOffId === false) {
            $db->insert('kimai2_activities', [
                'project_id' => $projectId,
                'name' => self::COMP_OFF,
                'comment' => 'Comp-off for approved additional hours, logged automatically.',
                'visible' => 1,
                'color' => self::COMP_OFF_COLOR,
                'billable' => 0,
                'time_budget' => 0,
                'budget' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $compOffId = $db->lastInsertId();
        } else {
            $db->executeStatement('UPDATE kimai2_activities SET color = ? WHERE id = ? AND (color IS NULL OR color = \'\')', [self::COMP_OFF_COLOR, $compOffId]);
        }

        // in the category "Leave & Time Off", like Leave
        $phaseId = $db->fetchOne('SELECT id FROM kimai2_phases WHERE name = ?', [self::PHASE]);
        if ($phaseId !== false && $db->fetchOne('SELECT 1 FROM kimai2_phase_activities WHERE phase_id = ? AND activity_id = ?', [$phaseId, $compOffId]) === false) {
            $db->insert('kimai2_phase_activities', ['phase_id' => $phaseId, 'activity_id' => $compOffId]);
        }

        // the task "Comp Off" moves from Leave to the new activity
        $db->executeStatement('UPDATE kimai2_activity_tasks SET activity_id = ? WHERE name = ?', [$compOffId, self::COMP_OFF]);

        // comp-off entries already in timesheets move as well
        if ($leaveId !== false) {
            $db->executeStatement(
                'UPDATE kimai2_timesheet t JOIN kimai2_timesheet_meta m ON m.timesheet_id = t.id AND m.name = ? AND m.value = ? SET t.activity_id = ? WHERE t.activity_id = ?',
                ['task', self::COMP_OFF, $compOffId, $leaveId]
            );
        }
    }

    public function down(Schema $schema): void
    {
    }
}
