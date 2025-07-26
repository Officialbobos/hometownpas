# Use an official PHP image with Apache
FROM php:8.3-apache

# Install necessary system dependencies for the MongoDB PHP extension
# and other utilities like git and unzip
RUN apt-get update && apt-get install -y \
    pkg-config \
    libssl-dev \
    libcurl4-openssl-dev \
    git \
    unzip \
    # We removed 'php8.3-bcmath' from here as it's installed via docker-php-ext-install
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* # Clean up to reduce image size

# Install the MongoDB PHP extension using PECL
# Ensure this happens BEFORE Composer install, as Composer might need it.
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install bcmath PHP extension (correct way for official images)
RUN docker-php-ext-install bcmath

# Install Composer globally (PHP's dependency manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory inside the container to your application's root
WORKDIR /var/www/html

# Copy custom Apache configuration to enable .htaccess
# Make sure you have created the .docker folder and 000-default.conf file
COPY ./.docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Disable the default site and enable our custom one (which allows .htaccess)
# This sequence is fine if it reliably enables your custom config.
RUN a2dissite 000-default.conf && a2ensite 000-default.conf

# Copy your entire application code into the container
# This should ideally be done AFTER installing PHP dependencies for optimal caching.
# However, for simpler apps or if files are needed before composer install, this is fine.
# For production, consider .dockerignore to exclude dev files.
COPY . /var/www/html/

# Install PHP dependencies using Composer
# This is crucial for autoloading MongoDB\Client.
RUN composer install --no-dev --optimize-autoloader

# Enable Apache's rewrite module (important for clean URLs if your app uses them)
RUN a2enmod rewrite

# Expose port 80 (standard HTTP port that Apache listens on)
EXPOSE 80

# The default command for the php-apache image starts the Apache web server
# CMD is implicitly handled by the base image.