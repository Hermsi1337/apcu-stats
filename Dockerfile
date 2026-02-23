FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && printf "\n" | pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

EXPOSE 8080

CMD ["php", "-d", "apc.enable_cli=1", "-S", "0.0.0.0:8080", "-t", "/app"]
