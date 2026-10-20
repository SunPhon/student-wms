FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod 755 /var/www/html

EXPOSE 80
CMD ["bash", "-lc", "php cloud-init.php && apache2-foreground"]
