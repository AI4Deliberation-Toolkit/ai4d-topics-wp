FROM php:7.4-cli-bullseye

# System deps needed by the WP test framework installer and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    subversion \
    default-mysql-client \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zlib1g-dev \
    curl \
    ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions WordPress core + PHPUnit need
RUN docker-php-ext-install mysqli mbstring xml zip pdo pdo_mysql

# Composer (multi-stage copy from the official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
