#!/usr/bin/env bash
# Genera finanzas-deploy.zip listo para subir a Hostinger (vendor + assets ya compilados).
set -euo pipefail
cd "$(dirname "$0")/.."
npm ci && npm run build
TMP=$(mktemp -d)
rsync -a --exclude node_modules --exclude .git --exclude '.env' --exclude ".env.*" --exclude 'database/*.sqlite' \
      --exclude 'storage/logs/*' --exclude 'storage/framework/*/*' --exclude tests ./ "$TMP/"
mkdir -p "$TMP/storage/framework/"{cache,sessions,views} "$TMP/storage/logs"
( cd "$TMP" && composer install --no-dev --optimize-autoloader --no-interaction && rm -f deploy/build-zip.sh )
( cd "$TMP" && zip -qr "$OLDPWD/finanzas-deploy.zip" . -x '.git/*' )
echo "Listo: finanzas-deploy.zip"
