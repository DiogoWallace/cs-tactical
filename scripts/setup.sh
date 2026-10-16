#!/usr/bin/env bash
# Prepara o ambiente local. Seguro para rodar mais de uma vez:
# não sobrescreve arquivos .env existentes nem gera nova APP_KEY se já houver uma.
set -euo pipefail

cd "$(dirname "$0")/.."

copy_env() {
  local example="$1" target="$2"
  if [[ -f "$target" ]]; then
    echo "• $target já existe, mantendo o arquivo atual."
  else
    cp "$example" "$target"
    echo "• $target criado a partir de $example."
  fi
}

echo "1/4 Arquivos de ambiente"
copy_env .env.example .env
copy_env apps/api/.env.example apps/api/.env

uid="$(id -u)"
gid="$(id -g)"
if [[ "$uid" == "0" ]]; then
  # Rodar como root no WSL não é recomendado; os containers precisam de um UID comum.
  echo "! Executando como root: mantendo HOST_UID/HOST_GID=1000. Prefira usar seu usuário normal do WSL."
  uid=1000
  gid=1000
fi
sed -i "s/^HOST_UID=.*/HOST_UID=${uid}/; s/^HOST_GID=.*/HOST_GID=${gid}/" .env
echo "• HOST_UID=${uid} e HOST_GID=${gid} configurados no .env."

echo "2/4 Build das imagens"
docker compose build

echo "3/4 Dependências da API (composer install no container)"
docker compose run --rm --no-deps api composer install --no-interaction

echo "4/4 Chave da aplicação Laravel"
if grep -qE '^APP_KEY=.+' apps/api/.env; then
  echo "• APP_KEY já definida."
else
  docker compose run --rm --no-deps api php artisan key:generate --ansi
fi

echo "Pronto. Próximos passos: make up && make migrate"
