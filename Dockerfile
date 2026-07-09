FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2dismod mpm_event \
    && a2enmod mpm_prefork

COPY docker-php.conf /etc/apache2/conf-available/docker-php.conf
RUN a2enconf docker-php

COPY index.php /var/www/html/index.php
COPY tenants/  /var/www/html/tenants/
COPY .htaccess /var/www/html/.htaccess

RUN chown -R www-data:www-data /var/www/html \
    && chmod 1777 /var/www/html
