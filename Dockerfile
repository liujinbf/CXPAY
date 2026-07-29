FROM php:8.1-cli-alpine

# 安装必要系统依赖与 PHP 扩展 (pcntl, pdo_mysql, redis, bcmath, sockets)
RUN apk add --no-cache \
    git \
    curl \
    curl-dev \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pcntl pdo_mysql sockets bcmath mbstring curl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . /app

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

ENV PORT=8787 \
    APP_DEBUG=false
EXPOSE 8787

CMD ["sh", "-c", "php start.php start"]
