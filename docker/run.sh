#!/bin/bash
echo 'test'
wwwDir="/var/www"
cp "${wwwDir}/docker/php.ini" "/etc/php5/apache2/php.ini"
cp "${wwwDir}/docker/vhost" "/etc/apache2/sites-available/default"

bower install --allow-root
if [ -n "${DEV_UID}" ]; then
    usermod -u ${DEV_UID} www-data
fi
if [ -n "${DEV_GID}" ]; then
    groupmod -g ${DEV_GID} www-data
fi

chown -R www-data:www-data "/var/www"

service apache2 restart
mailcatcher --http-ip=0.0.0.0

tail -f /dev/null