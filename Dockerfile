FROM php:8.1-cli-alpine

# 安装必要系统依赖与 PHP 扩展 (pcntl, pdo_mysql, redis, bcmath, sockets)
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pcntl pdo_mysql sockets bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /app

COPY . /app

EXPOSE 8787

CMD ["php", "-S", "0.0.0.0:8787", "server.php"]
