#!/bin/sh
# Selects the HTTP or HTTPS Nginx configuration when the production container starts.
# Docker Compose mounts certificates and proxy configuration before this script starts Nginx.
set -eu

certificate="/etc/letsencrypt/live/${PROD_DOMAIN}/fullchain.pem"
private_key="/etc/letsencrypt/live/${PROD_DOMAIN}/privkey.pem"

if [ -f "${certificate}" ] && [ -f "${private_key}" ]; then
    cp /etc/nginx/prod/https.conf /etc/nginx/conf.d/default.conf
    echo "TLS certificates detected; HTTPS mode enabled for ${PROD_DOMAIN}"
else
    cp /etc/nginx/prod/http.conf /etc/nginx/conf.d/default.conf
    echo "TLS certificates unavailable; temporary HTTP mode enabled for ${PROD_DOMAIN} - please renew certificates"
fi

exec nginx -g "daemon off;"
