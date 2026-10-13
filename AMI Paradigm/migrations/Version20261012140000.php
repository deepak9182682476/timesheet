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
 * Team Allocation: each person allocated to a project gets an Allocation ID, and each time they work on it a period
 * (Allocation No. 1, 2 ...) with a from and to date.
 */
final class Version20261012140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project allocations and their periods';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_project_allocations')) {
            $this->addSql('CREATE TABLE kimai2_project_allocations (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(20) NOT NULL,
                project_id INT NOT NULL,
                user_id INT NOT NULL,
                UNIQUE INDEX UNIQ_ALLOC_CODE (code),
                UNIQUE INDEX UNIQ_ALLOC_PROJECT_USER (project_id, user_id),
                INDEX IDX_ALLOC_USER (user_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE kimai2_project_allocations ADD CONSTRAINT FK_ALLOC_PROJECT FOREIGN KEY (project_id) REFERENCES kimai2_projects (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE kimai2_project_allocations ADD CONSTRAINT FK_ALLOC_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
        }
        if (!$schema->hasTable('kimai2_project_allocation_periods')) {
            $this->addSql('CREATE TABLE kimai2_project_allocation_periods (
                id INT AUTO_INCREMENT NOT NULL,
                allocation_id INT NOT NULL,
                number INT NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                created_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_ALLOC_PERIOD_NUMBER (allocation_id, number),
                INDEX IDX_ALLOC_PERIOD_CREATED (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE kimai2_project_allocation_periods ADD CONSTRAINT FK_ALLOC_PERIOD_ALLOC FOREIGN KEY (allocation_id) REFERENCES kimai2_project_allocations (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE kimai2_project_allocation_periods ADD CONSTRAINT FK_ALLOC_PERIOD_CREATED FOREIGN KEY (created_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_project_allocation_periods');
        $this->addSql('DROP TABLE kimai2_project_allocations');
    }
}
