FROM php:8.4-fpm

# 系统依赖（国内走阿里云 Debian 镜像，避免默认源慢）
RUN sed -i 's|http://deb.debian.org|http://mirrors.aliyun.com|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展（Laravel + 队列 + 文件上传 + Redis 会话/缓存需要）
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# 长任务队列 worker 入口（Phase 2 视频出片用）
# CMD ["php", "artisan", "queue:work"]
