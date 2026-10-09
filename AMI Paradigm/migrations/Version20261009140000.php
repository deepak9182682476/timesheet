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
 * Was: comp-off requests (removed again). Kept as a migration that changes nothing, so a database that already
 * ran it and one that did not are in the same state as far as the migrations go. Columns it may have added
 * (comp_worked_date, comp_reason, comp_worked_hours, comp_half_day, decision_seen) are simply not used.
 */
final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comp-off (removed): nothing to do';
    }

    public function up(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
    }

    public function down(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
    }
}
