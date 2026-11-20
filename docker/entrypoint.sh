#!/bin/sh
set -e

# Prepare directories
mkdir -p /run/mysqld /var/lib/mysql
chown -R mysql:mysql /run/mysqld /var/lib/mysql

# Initialize MariaDB data directory on first run
if [ ! -d /var/lib/mysql/mysql ]; then
    mysql_install_db --user=mysql --datadir=/var/lib/mysql --skip-test-db
fi

# Start MariaDB
mariadbd --user=mysql --datadir=/var/lib/mysql &

# Wait for MariaDB to be ready via socket (30s timeout)
i=0
until mariadb --socket=/run/mysqld/mysqld.sock -e "SELECT 1" >/dev/null 2>&1; do
    i=$((i+1))
    [ $i -ge 30 ] && echo "MariaDB failed to start" && exit 1
    sleep 1
done

# Apply schema (all statements are idempotent)
mariadb --socket=/run/mysqld/mysqld.sock < /var/www/html/docker/init.sql

# Ensure data and ssh_keys directories exist with correct ownership
mkdir -p /var/www/html/data /var/www/html/ssh_keys
chown -R www-data:www-data /var/www/html/data /var/www/html/ssh_keys

# Nginx temp dir must be writable by the worker user (www-data)
mkdir -p /var/lib/nginx/tmp/client_body
chown -R www-data:www-data /var/lib/nginx/tmp

# PHP sessions live outside /tmp so the tmp reaper and typst never touch them
mkdir -p /var/lib/php/sessions
chown www-data:www-data /var/lib/php/sessions
chmod 700 /var/lib/php/sessions

# Compile workspaces do not survive a restart (their watcher processes are gone)
rm -rf /tmp/typst_proj_*

# Reap idle typst watchers
su -s /bin/sh www-data -c /reaper.sh &

# Start php-fpm in background
php-fpm --daemonize

# Start nginx in foreground
exec nginx -g 'daemon off;'
