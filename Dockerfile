FROM php:8.2-apache

# Enable Apache mod_rewrite if needed
RUN a2enmod rewrite

# Copy project files into Apache root
COPY . /var/www/html/

# Ensure data directory exists and is writable
RUN mkdir -p /var/www/html/data && chmod -R 777 /var/www/html/data

EXPOSE 80
