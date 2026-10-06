# Stage 1: build the React app (outputs to /app)
FROM node:22-alpine AS frontend-build
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# Set to 0 to hide the demo-account hints on the login page
ARG VITE_SHOW_DEMO=1
ENV VITE_SHOW_DEMO=${VITE_SHOW_DEMO}
RUN npm run build

# Stage 2: Apache + PHP + (optional) local MariaDB for demo mode
FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/99-ffms.cnf
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/start.sh /usr/local/bin/start-ffms

WORKDIR /var/www/html/ffms
COPY .htaccess index.php ./
COPY api/ api/
COPY bin/ bin/
COPY database/ database/
COPY --from=frontend-build /app ./app
RUN mkdir -p storage/backups storage/logs \
    && printf '<?php\nheader("Location: ffms/app/");\nexit;\n' > /var/www/html/index.php \
    && sed -i 's/\r$//' /usr/local/bin/start-ffms \
    && chmod +x /usr/local/bin/start-ffms \
    && chown -R www-data:www-data storage

ENV APP_ENV=production PORT=10000
EXPOSE 10000
CMD ["/usr/local/bin/start-ffms"]
