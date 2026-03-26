FROM mediawiki:1.39

# Install pandoc for document conversion
RUN apt-get update \
    && apt-get install -y --no-install-recommends pandoc \
    && rm -rf /var/lib/apt/lists/*

# Copy the test document served by Apache
COPY docker/test-document.html /var/www/html/test-document.html

# Copy the custom entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
