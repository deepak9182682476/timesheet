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
 * WFH requests (approved by the immediate supervisor) and the daily attendance entered by an administrator.
 */
final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFH requests and attendance';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_wfh_requests')) {
            $this->addSql('CREATE TABLE kimai2_wfh_requests (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                decided_by_id INT DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                reason LONGTEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                decision_comment LONGTEXT DEFAULT NULL,
                decision_seen TINYINT(1) DEFAULT 1 NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX IDX_WFH_USER (user_id),
                INDEX IDX_WFH_DECIDED (decided_by_id),
                INDEX IDX_WFH_USER_START (user_id, start_date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE kimai2_wfh_requests ADD CONSTRAINT FK_WFH_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE kimai2_wfh_requests ADD CONSTRAINT FK_WFH_DECIDED FOREIGN KEY (decided_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL');
        }
        if (!$schema->hasTable('kimai2_attendance')) {
            $this->addSql('CREATE TABLE kimai2_attendance (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                updated_by_id INT DEFAULT NULL,
                day DATE NOT NULL,
                present TINYINT(1) NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_ATTENDANCE_USER_DAY (user_id, day),
                INDEX IDX_ATT_UPDATED (updated_by_id),
                INDEX IDX_ATT_DAY (day),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE kimai2_attendance ADD CONSTRAINT FK_ATT_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE kimai2_attendance ADD CONSTRAINT FK_ATT_UPDATED FOREIGN KEY (updated_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_attendance');
        $this->addSql('DROP TABLE kimai2_wfh_requests');
    }
}
