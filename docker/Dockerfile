# Backend PHP do Board Point (Apache + PHP 8.2)
FROM php:8.2-apache

# Extensão usada pelo config.php (PDO + MySQL/MariaDB)
RUN docker-php-ext-install pdo_mysql

# Uploads de fotos até 10 MB (o padrão do PHP é 2 MB)
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Pasta das fotos enviadas pelo admin, com dono www-data (usuário do Apache).
# Quando o volume "uploads" é criado, ele herda esta permissão.
RUN mkdir -p /var/www/html/produtos/uploads \
    && chown -R www-data:www-data /var/www/html/produtos

EXPOSE 80
