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

/**
 * Events are either an activity (team lunch, training: its hours are logged in everybody's timesheet) or
 * information only (the head visits, come in formals). The hours of activities are logged under the new
 * category "Team Events" of Non-Project Activities, on the activity "Team Event".
 * Events that exist already stay information only, so nothing is logged for them unless somebody changes them.
 */
final class Version20261009130000 extends AbstractMigration
{
    private const PROJECT = 'Non-Project Activities';
    private const PHASE = 'Team Events';
    private const ACTIVITY = 'Team Event';

    public function getDescription(): string
    {
        return 'Activity or information events, and the category Team Events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE kimai2_team_events ADD event_kind VARCHAR(20) DEFAULT 'information' NOT NULL");

        $db = $this->connection;
        $projectId = $db->fetchOne('SELECT id FROM kimai2_projects WHERE name = ?', [self::PROJECT]);
        if ($projectId === false) {
            return;
        }

        $activityId = $db->fetchOne('SELECT id FROM kimai2_activities WHERE name = ? AND project_id = ?', [self::ACTIVITY, $projectId]);
        if ($activityId === false) {
            $db->insert('kimai2_activities', [
                'project_id' => $projectId,
                'name' => self::ACTIVITY,
                'comment' => 'Hours of team events (team lunch, training ...), logged automatically.',
                'visible' => 1,
                'billable' => 0,
                'time_budget' => 0,
                'budget' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $activityId = $db->lastInsertId();
        }

        $phaseId = $db->fetchOne('SELECT id FROM kimai2_phases WHERE name = ?', [self::PHASE]);
        if ($phaseId === false) {
            $position = (int) $db->fetchOne('SELECT COALESCE(MAX(position), 0) FROM kimai2_phases WHERE project_id = ?', [$projectId]);
            $db->insert('kimai2_phases', ['name' => self::PHASE, 'project_id' => $projectId, 'position' => $position + 1]);
            $phaseId = $db->lastInsertId();
        }
        if ($db->fetchOne('SELECT 1 FROM kimai2_phase_activities WHERE phase_id = ? AND activity_id = ?', [$phaseId, $activityId]) === false) {
            $db->insert('kimai2_phase_activities', ['phase_id' => $phaseId, 'activity_id' => $activityId]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_team_events DROP event_kind');
    }
}
