#!/bin/sh
set -eu

mkdir -p var/cache var/log var/data var/sessions var/plugins var/invoices var/export
chown -R www-data:www-data var

echo "Waiting for the database..."
i=0
while [ "$i" -lt 60 ]; do
    if php -r '
        $url = getenv("DATABASE_URL");
        $parts = parse_url($url);
        if ($parts === false || !isset($parts["host"], $parts["user"], $parts["pass"], $parts["path"])) {
            fwrite(STDERR, "DATABASE_URL is invalid\n");
            exit(1);
        }
        $port = $parts["port"] ?? 3306;
        $name = ltrim($parts["path"], "/");
        $name = explode("?", $name)[0];
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $parts["host"], $port, $name);
        new PDO($dsn, $parts["user"], $parts["pass"]);
    '; then
        break
    fi
    i=$((i + 1))
    sleep 2
done

if [ "$i" -ge 60 ]; then
    echo "Database did not become ready." >&2
    exit 1
fi

su -s /bin/sh www-data -c "php bin/console kimai:install -n"
su -s /bin/sh www-data -c "php bin/console kimai:user:create admin admin@timesheet.local ROLE_SUPER_ADMIN 'Timesheet123!' --ignore-existing"
su -s /bin/sh www-data -c "php bin/console kimai:user:create manager manager@timesheet.local ROLE_ADMIN 'Manager123!' --ignore-existing"
su -s /bin/sh www-data -c "php bin/console kimai:user:create lead lead@timesheet.local ROLE_TEAMLEAD 'Lead12345!' --ignore-existing"
su -s /bin/sh www-data -c "php bin/console kimai:user:create employee employee@timesheet.local ROLE_USER 'Employee123!' --ignore-existing"
su -s /bin/sh www-data -c "php bin/console dbal:run-sql \"UPDATE kimai2_users SET alias = CASE username WHEN 'admin' THEN 'Admin' WHEN 'manager' THEN 'Project manager' WHEN 'lead' THEN 'Project lead' WHEN 'employee' THEN 'Employee' ELSE alias END WHERE username IN ('admin','manager','lead','employee')\""
su -s /bin/sh www-data -c "php bin/console dbal:run-sql \"INSERT INTO kimai2_user_preferences (user_id, name, value) SELECT id, '__wizards__', 'intro,profile' FROM kimai2_users WHERE username IN ('admin','manager','lead','employee') AND NOT EXISTS (SELECT 1 FROM kimai2_user_preferences p WHERE p.user_id = kimai2_users.id AND p.name = '__wizards__')\""

exec apache2-foreground
