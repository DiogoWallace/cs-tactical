#!/usr/bin/env bash
# Prepara o ambiente local: cria o .env da raiz a partir do .env.example
# e ajusta HOST_UID/HOST_GID para o usuário atual do WSL.
# Seguro para rodar mais de uma vez: não sobrescreve um .env existente.
set -euo pipefail

cd "$(dirname "$0")/.."

if [[ -f .env ]]; then
  echo "• .env já existe, mantendo o arquivo atual."
else
  cp .env.example .env
  echo "• .env criado a partir de .env.example."
fi

uid="$(id -u)"
gid="$(id -g)"
sed -i "s/^HOST_UID=.*/HOST_UID=${uid}/; s/^HOST_GID=.*/HOST_GID=${gid}/" .env
echo "• HOST_UID=${uid} e HOST_GID=${gid} configurados no .env."
