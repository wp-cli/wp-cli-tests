# Image for running the Behat scenarios of a WP-CLI package in throwaway containers.
# Its PHP version has to match the one the package's Composer dependencies were installed for.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		curl git jq less mariadb-client subversion unzip vim-tiny zip \
		libfreetype6-dev libjpeg-dev libpng-dev libzip-dev \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install exif gd mysqli pdo_mysql zip \
	&& ( php -m | grep -qi '^zend opcache$' || docker-php-ext-install opcache ) \
	&& rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
