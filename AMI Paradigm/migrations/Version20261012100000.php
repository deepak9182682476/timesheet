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
 * Extra Hours Claim: the time worked (from, to), so that the same or overlapping hours cannot be claimed twice.
 */
final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time worked of extra hours claims';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('kimai2_additional_hours');
        if (!$table->hasColumn('from_time')) {
            $this->addSql('ALTER TABLE kimai2_additional_hours ADD from_time TIME DEFAULT NULL');
        }
        if (!$table->hasColumn('to_time')) {
            $this->addSql('ALTER TABLE kimai2_additional_hours ADD to_time TIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE kimai2_additional_hours DROP from_time, DROP to_time');
    }
}
