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
 * Office locations of the first people (the holiday calendar decides their holidays by office):
 * Deepak, Rama and Srinivas work at Hyderabad, Sai at Vadodara.
 * Everybody else is set by an administrator on the person's preferences ("Office location").
 * A person who already has a location keeps it.
 */
final class Version20261008130000 extends AbstractMigration
{
    private const PEOPLE = [
        'Hyderabad' => ['deepak', 'rama', 'srinivas'],
        'Vadodara' => ['sai'],
    ];

    public function getDescription(): string
    {
        return 'Office location of Deepak, Rama, Srinivas (Hyderabad) and Sai (Vadodara)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PEOPLE as $location => $names) {
            foreach ($names as $name) {
                // by username, or by the name shown (first name), so "Srinivas Bonthu" is found as well
                $this->addSql(
                    "INSERT INTO kimai2_user_preferences (user_id, name, value)
                     SELECT u.id, 'office_location', ?
                     FROM kimai2_users u
                     WHERE (LOWER(u.username) = ? OR LOWER(u.username) LIKE ? OR LOWER(COALESCE(u.alias, '')) = ? OR LOWER(COALESCE(u.alias, '')) LIKE ?)
                       AND NOT EXISTS (SELECT 1 FROM kimai2_user_preferences p WHERE p.user_id = u.id AND p.name = 'office_location')",
                    [$location, $name, $name . '.%', $name, $name . ' %']
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM kimai2_user_preferences WHERE name = 'office_location'");
    }
}
