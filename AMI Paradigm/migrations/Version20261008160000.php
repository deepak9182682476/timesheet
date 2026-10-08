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
 * New customers used to get Germany as their country (the default of the software). The default is India now;
 * customers that were saved with Germany only because of that default are set to India as well.
 */
final class Version20261008160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers in India instead of the old default Germany';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE kimai2_customers SET country = 'IN' WHERE country = 'DE'");
        $this->addSql("UPDATE kimai2_configuration SET value = 'IN' WHERE name = 'defaults.customer.country' AND value = 'DE'");
    }

    public function down(Schema $schema): void
    {
    }
}
