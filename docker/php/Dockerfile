# ---------------------------------------------------------------------------
# ScrumSpace Laravel app — FrankenPHP (PHP 8.5 + Caddy dalam satu image)
# Build : docker build -f docker/php/Dockerfile -t scrumspace-app .
# ---------------------------------------------------------------------------

# ---- Tahap 1: build asset frontend (Vite) ----
FROM node:24-alpine AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---- Tahap 2: aplikasi ----
FROM dunglas/frankenphp:1-php8.5

# Tool sistem: git (worktree/clone), unzip (composer), posix (demo server)
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Ekstensi PHP yang dibutuhkan Laravel + SQLite
RUN install-php-extensions \
    pdo_sqlite \
    mbstring \
    xml \
    curl \
    zip \
    bcmath \
    intl \
    opcache \
    pcntl \
    posix

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependency PHP (layer cache: hanya diulang jika composer.* berubah)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Salin seluruh source + hasil build frontend
COPY . .
COPY --from=frontend /build/public/build ./public/build

RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# FrankenPHP melayani /app/public otomatis.
# Atur domain via env SERVER_NAME (lihat docker-compose.yml).
ENTRYPOINT ["/entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
