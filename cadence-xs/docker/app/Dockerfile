FROM composer:2 AS composer

FROM php:8.5-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libc-client-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libkrb5-dev \
        libonig-dev \
        libpng-dev \
        libssl-dev \
        libwebp-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd mbstring pdo_mysql \
    && pecl install imap-1.0.3 \
    && docker-php-ext-enable imap \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
