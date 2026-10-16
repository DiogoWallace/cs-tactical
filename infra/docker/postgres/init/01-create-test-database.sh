#!/usr/bin/env bash
# Executado apenas na primeira inicialização do volume do Postgres.
# Cria o banco usado pelos testes automatizados (Pest), separado do banco de desenvolvimento.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER ${POSTGRES_USER};
SQL
