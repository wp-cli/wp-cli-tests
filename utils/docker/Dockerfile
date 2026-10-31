# Image for running the Behat scenarios of a WP-CLI package in throwaway containers.
# Its PHP version has to match the one the package's Composer dependencies were installed for.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# Images of older PHP versions are based on Debian releases whose packages have moved to the archive.
RUN install_packages() { \
		apt-get update && apt-get install -y --no-install-recommends \
			curl git jq less mariadb-client procps subversion unzip vim-tiny zip \
			libfreetype6-dev libjpeg-dev libpng-dev libzip-dev; \
	} \
	&& ( install_packages || { \
		sed -i -e 's|deb.debian.org|archive.debian.org|g' -e '/-updates/d' /etc/apt/sources.list \
		&& echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive \
		&& install_packages; \
	} ) \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install exif gd mysqli pdo_mysql zip \
	&& ( php -m | grep -qi '^zend opcache$' || docker-php-ext-install opcache ) \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
