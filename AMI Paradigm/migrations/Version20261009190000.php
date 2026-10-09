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
 * Task Creation: an estimated time (free text) for every item.
 */
final class Version20261009190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estimated time of work items';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('kimai2_work_items')->hasColumn('estimate')) {
            $this->addSql('ALTER TABLE kimai2_work_items ADD estimate VARCHAR(100) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_work_items DROP estimate');
    }
}
