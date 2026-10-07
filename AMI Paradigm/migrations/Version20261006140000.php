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
 * Activities and standard tasks of the remaining Non-Project Activities phases
 * (phase > activity > task). Training & Development was mapped in the previous migration.
 */
final class Version20261006140000 extends AbstractMigration
{
    private const PROJECT = 'Non-Project Activities';
    private const MAPPING = [
        'Seminars & Conferences' => [
            'External Events' => ['Attending Seminar / Webinar', 'Attending Industry Conference', 'Attending Meetup / User Group'],
        ],
        'Knowledge Sharing' => [
            'Internal Sessions' => ['Giving Internal Training Session', 'Conducting Workshop', 'Tech Talk / Brown Bag Presentation', 'Mentoring / Coaching a Colleague'],
        ],
        'Company & Admin' => [
            'Meetings & Administration' => ['Team Meeting', 'All-Hands / Town Hall', 'Performance Review / 1:1', 'HR / Administrative Tasks'],
            'Recruitment' => ['Candidate Interviewing', 'Resume Screening'],
        ],
        'Pre-Sales & Practice' => [
            'Business Development' => ['Proposal / RFP Writing', 'Practice Development'],
        ],
        'Leave & Time Off' => [
            'Leave' => ['Sick Leave', 'Vacation / PTO', 'Public Holiday'],
        ],
        'Bench' => [
            'Unallocated Time' => ['Bench / Unallocated Time'],
        ],
    ];

    public function getDescription(): string
    {
        return 'Map the activities and tasks of the remaining non-project phases';
    }

    public function up(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
        $db = $this->connection;

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
