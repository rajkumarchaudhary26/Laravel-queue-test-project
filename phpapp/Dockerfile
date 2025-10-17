FROM php:8.2-fpm

RUN apt-get update && apt-get upgrade -y && \
    apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev zip git unzip libpq-dev supervisor nginx && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd pdo pdo_pgsql && \
    apt-get install -y tzdata && \
    ln -sf /usr/share/zoneinfo/Asia/Tokyo /etc/localtime && \
    echo "Asia/Tokyo" > /etc/timezone && \
    dpkg-reconfigure -f noninteractive tzdata && \
    apt-get install -y iputils-ping net-tools procps vim wget curl lsof && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates wget gnupg; \
    install -d -m 0755 /etc/apt/keyrings; \
    wget -qO /etc/apt/keyrings/postgresql.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    gpg --dearmor -o /etc/apt/keyrings/postgresql.gpg /etc/apt/keyrings/postgresql.asc; \
    . /etc/os-release; \
    echo "deb [signed-by=/etc/apt/keyrings/postgresql.gpg] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-16; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

RUN apt-get update && \
    apt-get install -y unzip curl && \
    curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o "awscliv2.zip" && \
    unzip awscliv2.zip && \
    ./aws/install && \
    rm -rf awscliv2.zip aws

RUN pecl install xdebug && docker-php-ext-enable xdebug

RUN pecl install redis && docker-php-ext-enable redis

RUN docker-php-ext-install pcntl

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get update && apt-get install -y zip libzip-dev && \
    docker-php-ext-configure zip && \
    docker-php-ext-install zip

RUN mkdir -p /var/log/supervisor

RUN sed -i 's|listen = 9000|listen = 0.0.0.0:9000|' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|listen.allowed_clients = 127.0.0.1|;listen.allowed_clients = 127.0.0.1|' /usr/local/etc/php-fpm.d/www.conf

# Configure nginx
COPY nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure PHP-FPM to run as www-data
RUN sed -i 's/user = www-data/user = www-data/g' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/group = www-data/group = www-data/g' /usr/local/etc/php-fpm.d/www.conf

# Configure nginx to run as www-data
RUN sed -i 's/user www-data;/user www-data;/g' /etc/nginx/nginx.conf || \
    sed -i '1i user www-data;' /etc/nginx/nginx.conf

COPY supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create a startup script to fix permissions
RUN echo '#!/bin/bash\n\
if [ -d "/var/www/phpapp/storage" ]; then\n\
    chown -R www-data:www-data /var/www/phpapp/storage\n\
    chmod -R 775 /var/www/phpapp/storage\n\
fi\n\
if [ -d "/var/www/phpapp/bootstrap/cache" ]; then\n\
    chown -R www-data:www-data /var/www/phpapp/bootstrap/cache\n\
    chmod -R 775 /var/www/phpapp/bootstrap/cache\n\
fi\n\
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf\n\
' > /usr/local/bin/startup.sh && chmod +x /usr/local/bin/startup.sh

CMD ["/usr/local/bin/startup.sh"]

EXPOSE 8000
EXPOSE 8091