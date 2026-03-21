FROM php:8.3-apache

# Enable Apache mod_rewrite (optional)
RUN a2enmod rewrite

# Set document root
WORKDIR /var/www/html

# Copy PHP API
COPY get_posts.php .

# Expose port 8080
EXPOSE 8080

# Use port 8080
ENV APACHE_PORT=8080

# Usage:
# docker build -f Dockerfile.php -t ig-api .
# docker run -p 8080:8080 --rm ig-api
# curl http://localhost:8080/get_posts.php?username=nasa
