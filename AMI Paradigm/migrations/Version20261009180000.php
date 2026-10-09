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
 * Employee ID (the users' staff number) is unique. Empty values are cleared to NULL first;
 * if two users already share an ID, the index is left out and the form still refuses duplicates.
 */
final class Version20261009180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique Employee ID for users';
    }

    public function up(Schema $schema): void
    {
        $db = $this->connection;
        $db->executeStatement("UPDATE kimai2_users SET account = NULL WHERE TRIM(account) = ''");
        $db->executeStatement('UPDATE kimai2_users SET account = TRIM(account) WHERE account IS NOT NULL');

        $duplicates = (int) $db->fetchOne('SELECT COUNT(*) FROM (SELECT account FROM kimai2_users WHERE account IS NOT NULL GROUP BY account HAVING COUNT(*) > 1) d');
        if ($duplicates === 0 && !$schema->getTable('kimai2_users')->hasIndex('UNIQ_USERS_EMPLOYEE_ID')) {
            $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_EMPLOYEE_ID ON kimai2_users (account)');
        }
    }

    public function down(Schema $schema): void
    {
    }
}
