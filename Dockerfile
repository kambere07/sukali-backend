FROM ghcr.io/gaetan26/sukali-php-base:latest

WORKDIR /var/www/html

COPY ./app/. /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
