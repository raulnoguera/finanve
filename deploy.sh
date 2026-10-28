#!/usr/bin/env bash
# Despliega Finanve por SSH SOLO en la carpeta del sitio configurado.
# Lee los datos de .deploy.env (ver deploy.env.example). Nunca adivina la ruta:
# si el .env remoto no apunta a $SITE, se detiene.
set -euo pipefail
cd "$(dirname "$0")"
[ -f .deploy.env ] || { echo "Falta .deploy.env (copia deploy.env.example)"; exit 1; }
# shellcheck disable=SC1091
source .deploy.env
KEY="${KEY/#\~/$HOME}"
USER_HOST="${REMOTE%@*}"
DIR=/home/$USER_HOST/domains/$SITE/public_html
SSH=(ssh -i "$KEY" -p "$SSH_PORT" "$REMOTE")

# Guarda: el .env de la carpeta destino debe apuntar al dominio de Finanve
"${SSH[@]}" "grep -q '$SITE' $DIR/.env" || { echo "ABORTADO: el .env de $DIR no apunta a $SITE"; exit 1; }
echo "Destino verificado: $SITE"

for x in app routes resources/views database/migrations; do
  scp -i "$KEY" -P "$SSH_PORT" -rq "$x" "$REMOTE:$DIR/$(dirname "$x")/"
done
[ -d public/build ] && scp -i "$KEY" -P "$SSH_PORT" -rq public/build "$REMOTE:$DIR/public/"

"${SSH[@]}" "cd $DIR && $PHP artisan migrate --force && $PHP artisan route:cache && $PHP artisan view:clear && $PHP artisan view:cache && $PHP artisan cron:status"
curl -s -o /dev/null -w "entrar %{http_code}\n" "https://$SITE/entrar"
