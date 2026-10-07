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
 * Creates the "Non-Project Activities" project, open to every person (it has no team),
 * and the first set of phases. More phases are added by an administrator on the Phases page.
 */
final class Version20261006110000 extends AbstractMigration
{
    private const PROJECT = 'Non-Project Activities';
    private const CUSTOMER = 'AMI Paradigm (internal)';
    private const NON_PROJECT_PHASES = ['Training & Development', 'Seminars & Conferences', 'Knowledge Sharing', 'Company & Admin', 'Pre-Sales & Practice', 'Leave & Time Off', 'Bench'];
    private const PROJECT_PHASES = ['Discovery & Design', 'Core Build'];

    public function getDescription(): string
    {
        return 'Add the Non-Project Activities project and the first phases';
    }

    public function up(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
        $db = $this->connection;
        $now = date('Y-m-d H:i:s');

        $projectId = $db->fetchOne('SELECT id FROM kimai2_projects WHERE name = ?', [self::PROJECT]);
        if ($projectId === false) {
            $customerId = $db->fetchOne('SELECT id FROM kimai2_customers WHERE name = ?', [self::CUSTOMER]);
            if ($customerId === false) {
                // same country, currency and time zone as the customers that exist already
                $like = $db->fetchAssociative('SELECT country, currency, timezone FROM kimai2_customers ORDER BY id ASC LIMIT 1');
                $db->insert('kimai2_customers', [
                    'name' => self::CUSTOMER,
                    'comment' => 'Holds the project for work that is not done for a customer project.',
                    'visible' => 1,
                    'country' => $like['country'] ?? 'IN',
                    'currency' => $like['currency'] ?? 'INR',
                    'timezone' => $like['timezone'] ?? 'Asia/Kolkata',
                    'billable' => 0,
                    'created_at' => $now,
                ]);
                $customerId = $db->lastInsertId();
            }
            $db->insert('kimai2_projects', [
                'customer_id' => $customerId,
                'name' => self::PROJECT,
                'comment' => 'Training, meetings, leave, bench and other work outside customer projects. Open to everyone.',
                'visible' => 1,
                'billable' => 0,
                'global_activities' => 1,
                'created_at' => $now,
            ]);
            $projectId = $db->lastInsertId();
        }

        $position = 0;
        foreach (self::NON_PROJECT_PHASES as $name) {
            $this->addPhase($name, (int) $projectId, ++$position);
        }
        $position = 0;
        foreach (self::PROJECT_PHASES as $name) {
            $this->addPhase($name, null, ++$position);
        }
    }

    private function addPhase(string $name, ?int $projectId, int $position): void
    {
        if ($this->connection->fetchOne('SELECT id FROM kimai2_phases WHERE name = ?', [$name]) !== false) {
            return;
        }
        $this->connection->insert('kimai2_phases', ['name' => $name, 'project_id' => $projectId, 'position' => $position]);
    }

    public function down(Schema $schema): void
    {
        // the project and phases may be in use by time entries: they are kept
    }
}
