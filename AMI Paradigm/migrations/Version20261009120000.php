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
 * "Phase" is called "Category" on screen now: the note on the built-in Pre-Sales project says so as well.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pre-Sales project note: Category instead of Phase';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE kimai2_projects SET comment = REPLACE(comment, 'Lead > Phase > Activity', 'Lead > Category > Activity') WHERE comment LIKE '%Lead > Phase > Activity%'");
    }

    public function down(Schema $schema): void
    {
    }
}
