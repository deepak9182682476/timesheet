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
 * First mapping of phase > activity > task:
 * - "Training & Development" gets its activities and their standard tasks
 * - the phases for normal projects get the activities that existed already, so they keep working
 *   now that a phase only offers the activities linked to it
 */
final class Version20261006130000 extends AbstractMigration
{
    private const PROJECT = 'Non-Project Activities';
    private const MAPPING = [
        'Training & Development' => [
            'Technical Training' => ['Attending Internal Training', 'Attending Vendor / Certification Training', 'Online Course / Self-Learning'],
            'Certification Prep' => ['Certification Exam Study', 'Certification Exam'],
        ],
    ];

    public function getDescription(): string
    {
        return 'Map the first activities and tasks to their phases';
    }

    public function up(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
        $db = $this->connection;

        // activities that existed before, usable on every project: keep them on the normal project phases
        $existing = $db->fetchFirstColumn('SELECT id FROM kimai2_activities WHERE project_id IS NULL');
        $projectPhases = $db->fetchFirstColumn('SELECT p.id FROM kimai2_phases p WHERE p.project_id IS NULL AND NOT EXISTS (SELECT 1 FROM kimai2_phase_activities pa WHERE pa.phase_id = p.id)');
        foreach ($projectPhases as $phaseId) {
            foreach ($existing as $activityId) {
                $db->insert('kimai2_phase_activities', ['phase_id' => $phaseId, 'activity_id' => $activityId]);
            }
        }

        $projectId = $db->fetchOne('SELECT id FROM kimai2_projects WHERE name = ?', [self::PROJECT]);
        if ($projectId === false) {
            return;
        }

        foreach (self::MAPPING as $phaseName => $activities) {
            $phaseId = $db->fetchOne('SELECT id FROM kimai2_phases WHERE name = ?', [$phaseName]);
            if ($phaseId === false) {
                continue;
            }
            foreach ($activities as $activityName => $tasks) {
                // the activity belongs to the Non-Project Activities project, so it is not offered on customer projects
                $activityId = $db->fetchOne('SELECT id FROM kimai2_activities WHERE name = ? AND project_id = ?', [$activityName, $projectId]);
                if ($activityId === false) {
                    $db->insert('kimai2_activities', [
                        'project_id' => $projectId,
                        'name' => $activityName,
                        'visible' => 1,
                        'billable' => 0,
                        'time_budget' => 0,
                        'budget' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $activityId = $db->lastInsertId();
                }
                if ($db->fetchOne('SELECT 1 FROM kimai2_phase_activities WHERE phase_id = ? AND activity_id = ?', [$phaseId, $activityId]) === false) {
                    $db->insert('kimai2_phase_activities', ['phase_id' => $phaseId, 'activity_id' => $activityId]);
                }
                $position = 0;
                foreach ($tasks as $taskName) {
                    ++$position;
                    if ($db->fetchOne('SELECT 1 FROM kimai2_activity_tasks WHERE name = ?', [$taskName]) === false) {
                        $db->insert('kimai2_activity_tasks', ['name' => $taskName, 'activity_id' => $activityId, 'position' => $position]);
                    }
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        // the activities and tasks may be in use by time entries: they are kept
    }
}
