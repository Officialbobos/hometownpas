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
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Install the MongoDB PHP extension using PECL
# Ensure this happens BEFORE Composer install, as Composer might need it.
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && echo "MongoDB install verification $(date +%s)" # ADD THIS LINE FOR CACHE BUSTING

# Install bcmath PHP extension (correct way for official images)
RUN docker-php-ext-install bcmath

# Install Composer globally (PHP's dependency manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory inside the container to your application's root
WORKDIR /var/www/html

# Copy custom Apache configuration to enable .htaccess
COPY ./.docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Disable the default site and enable our custom one
RUN a2dissite 000-default.conf && a2ensite 000-default.conf

# Copy your entire application code into the container
COPY . /var/www/html/

# Install PHP dependencies using Composer
RUN composer install --no-dev --optimize-autoloader


# Explicitly dump the Composer autoloader (stronger guarantee)
RUN composer dump-autoload --optimize --no-dev

# Enable Apache's rewrite module
RUN a2enmod rewrite

# Expose port 80

# CMD is implicitly handled by the base image.