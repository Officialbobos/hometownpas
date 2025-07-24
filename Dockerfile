# Use an official PHP image with Apache (good for typical web apps)
# You can choose a specific PHP version, e.g., php:8.2-apache or php:8.1-apache
FROM php:8.2-apache

# Install necessary system dependencies for the MongoDB PHP extension
# These are common packages required for many PHP extensions
RUN apt-get update && apt-get install -y \
    pkg-config \
    libssl-dev \
    libcurl4-openssl-dev \
    git \ # Git is useful if your composer.json has dev dependencies or private repos
    unzip \ # Unzip might be needed for composer
    && rm -rf /var/lib/apt/lists/*

# Install the MongoDB PHP extension using PECL
# This downloads, compiles, and enables the driver
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install Composer globally (PHP's dependency manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory inside the container to your application's root
WORKDIR /var/www/html

# Copy your entire application code into the container
# This copies everything from your local project root into /var/www/html in the container
COPY . /var/www/html/

# Install PHP dependencies using Composer
# --no-dev means it won't install development dependencies, which is good for production
# --optimize-autoloader creates a faster autoloader map
RUN composer install --no-dev --optimize-autoloader

# Enable Apache's rewrite module (important for clean URLs if your app uses them)
# Your functions.php might rely on .htaccess rules, which need mod_rewrite
RUN a2enmod rewrite

# Expose port 80 (standard HTTP port that Apache listens on)
EXPOSE 80

# The default command for the php-apache image starts the Apache web server
# You typically don't need a CMD instruction here unless you want to override
# the default behavior of the base image.