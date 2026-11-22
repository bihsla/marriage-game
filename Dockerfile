FROM php:8.3-apache
RUN a2enmod rewrite && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY . /var/www/html/
RUN mkdir -p /var/www/html/rooms
EXPOSE 80
CMD ["bash", "-lc", "mkdir -p /var/www/html/rooms && chown -R www-data:www-data /var/www/html/rooms && exec apache2-foreground"]
