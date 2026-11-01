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
		sed -i -e 's|deb.debian.org|archive.debian.org|g' -e 's|security.debian.org|archive.debian.org|g' -e '/-updates/d' /etc/apt/sources.list \
		&& echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive \
		&& install_packages; \
	} ) \
	&& ( docker-php-ext-configure gd --with-freetype --with-jpeg \
		|| docker-php-ext-configure gd --with-freetype-dir=/usr --with-jpeg-dir=/usr ) \
	&& docker-php-ext-install exif gd mysqli pdo_mysql zip \
	&& ( php -m | grep -qi '^zend opcache$' || docker-php-ext-install opcache ) \
	&& rm -rf /var/lib/apt/lists/*

# The SQLite integration plugin needs SQLite 3.37 or later, which older Debian releases do not ship.
# PHP links to the library, so a newer build in /usr/local/lib takes the place of the system one,
# with the same options as Debian's, as PHP uses the functions they enable.
ARG SQLITE_VERSION=3540100
ARG SQLITE_YEAR=2026
ARG SQLITE_SHA3_256=b6ced585c750916b1052667239a12c25a7ff34e7f06e1e6e01ecb0427657d9c4
RUN if dpkg --compare-versions "$(dpkg-query -W -f '${Version}' libsqlite3-0)" lt 3.37; then \
		cd /tmp \
		&& curl -fsSL -o sqlite.tar.gz "https://www.sqlite.org/${SQLITE_YEAR}/sqlite-autoconf-${SQLITE_VERSION}.tar.gz" \
		&& [ "$(openssl dgst -sha3-256 -r sqlite.tar.gz | cut -d' ' -f1)" = "${SQLITE_SHA3_256}" ] \
		&& tar xzf sqlite.tar.gz \
		&& cd "sqlite-autoconf-${SQLITE_VERSION}" \
		&& CFLAGS="-O2 -DSQLITE_ENABLE_COLUMN_METADATA=1 -DSQLITE_ENABLE_FTS3 -DSQLITE_ENABLE_FTS3_PARENTHESIS -DSQLITE_ENABLE_FTS4 -DSQLITE_ENABLE_FTS5 -DSQLITE_ENABLE_RTREE -DSQLITE_ENABLE_UNLOCK_NOTIFY -DSQLITE_SECURE_DELETE" \
			./configure --prefix=/usr/local --disable-static > /dev/null \
		&& make -j"$(nproc)" > /dev/null \
		&& make install > /dev/null \
		&& ldconfig \
		&& cd /tmp && rm -rf sqlite.tar.gz "sqlite-autoconf-${SQLITE_VERSION}"; \
	fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
