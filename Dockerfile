FROM php:8.2-apache

# Install system dependencies and SQLite development libraries
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    sqlite3

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Copy your project files into the Apache web root
COPY . /var/www/html/

# Set proper permissions if needed
RUN chown -R www-data:www-data /var/www/html