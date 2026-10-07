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
 * A day of approved leave is booked as 10 hours (it was 8 at first): the leave entries that were
 * created automatically with 8 hours are brought to 10. Entries people typed in themselves are not touched.
 */
final class Version20261006170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Book automatically created leave entries with 10 hours a day';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE kimai2_timesheet t
             JOIN kimai2_timesheet_meta m ON m.timesheet_id = t.id AND m.name = 'leave_request'
             SET t.duration = 36000, t.end_time = DATE_ADD(t.start_time, INTERVAL 10 HOUR)
             WHERE t.duration = 28800"
        );
    }

    public function down(Schema $schema): void
    {
        $this->preventEmptyMigrationWarning();
    }
}
