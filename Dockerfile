# Use official PHP + Apache image
FROM php:8.2-apache

# Enable Apache rewrite (optional but recommended)
RUN a2enmod rewrite

# Install PDO MySQL (adjust if you use PostgreSQL)
RUN docker-php-ext-install pdo pdo_mysql

# Copy project files into Apache root
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80
