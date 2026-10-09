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
 * Additional hours (weekend, festival or holiday, night shift) that the manager approves, and the comp-off
 * leave taken for them. A comp-off points at the additional hours it uses ("comp_credit_id").
 * "decision_seen" lets the bell tell people that their request was approved or rejected.
 * The task "Comp Off" is added to Non-Project Activities > Leave & Time Off > Leave for the time entries.
 */
final class Version20261009150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Additional hours and comp-off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_additional_hours (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            decided_by_id INT DEFAULT NULL,
            work_date DATE NOT NULL,
            reason VARCHAR(20) NOT NULL,
            hours DOUBLE PRECISION NOT NULL,
            credit_days DOUBLE PRECISION NOT NULL,
            description LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            decision_comment LONGTEXT DEFAULT NULL,
            decision_seen TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_AH_USER (user_id),
            INDEX IDX_AH_DECIDED (decided_by_id),
            INDEX IDX_AH_USER_DATE (user_id, work_date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE kimai2_additional_hours ADD CONSTRAINT FK_AH_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_additional_hours ADD CONSTRAINT FK_AH_DECIDED FOREIGN KEY (decided_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL');

        $events = $schema->getTable('kimai2_team_events');
        $this->addSql('ALTER TABLE kimai2_team_events ADD comp_credit_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE kimai2_team_events ADD CONSTRAINT FK_TE_COMP_CREDIT FOREIGN KEY (comp_credit_id) REFERENCES kimai2_additional_hours (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_TE_COMP_CREDIT ON kimai2_team_events (comp_credit_id)');
        // an earlier version (taken out again) may have added this column already
        if (!$events->hasColumn('decision_seen')) {
            $this->addSql('ALTER TABLE kimai2_team_events ADD decision_seen TINYINT(1) DEFAULT 1 NOT NULL');
        }

        $db = $this->connection;
        $activityId = $db->fetchOne(
            'SELECT a.id FROM kimai2_activities a JOIN kimai2_projects p ON p.id = a.project_id WHERE a.name = ? AND p.name = ?',
            ['Leave', 'Non-Project Activities']
        );
        if ($activityId !== false && $db->fetchOne('SELECT id FROM kimai2_activity_tasks WHERE name = ?', ['Comp Off']) === false) {
            $position = (int) $db->fetchOne('SELECT COALESCE(MAX(position), 0) FROM kimai2_activity_tasks WHERE activity_id = ?', [$activityId]);
            $db->insert('kimai2_activity_tasks', ['activity_id' => $activityId, 'name' => 'Comp Off', 'position' => $position + 1]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_team_events DROP FOREIGN KEY FK_TE_COMP_CREDIT');
        $this->addSql('ALTER TABLE kimai2_team_events DROP comp_credit_id');
        $this->addSql('DROP TABLE kimai2_additional_hours');
    }
}
