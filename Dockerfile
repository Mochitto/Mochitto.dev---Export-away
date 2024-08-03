# Use the official PHP image as a base
FROM php:8.2-cli

# Install dependencies
RUN apt-get update && \
  apt-get install -y libpq-dev wget && \
  docker-php-ext-install pgsql pdo_pgsql

# Download and extract csv2xlsx binary
RUN wget https://github.com/mentax/csv2xlsx/releases/download/v0.5.2/csv2xlsx_Linux_x86_64.tar.gz -O /tmp/csv2xlsx.tar.gz && \
  tar -xzf /tmp/csv2xlsx.tar.gz -C /usr/local/bin && \
  rm /tmp/csv2xlsx.tar.gz

# Copy the application files to the container
COPY ./php/ /usr/src/myapp

# Set the working directory
WORKDIR /usr/src/myapp

# Expose the port the application will run on
EXPOSE 3000

# Command to run the PHP built-in server
CMD [ "php", "-S", "0.0.0.0:3000", "-t", "." ]
