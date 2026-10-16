# Imagem da API Laravel (Nginx + PHP-FPM). Ver docs/decisions/0004-runtime-php-em-container.md
FROM serversideup/php:8.4-fpm-nginx AS base

USER root

# A imagem já traz as extensões necessárias: pdo_pgsql, redis, pcntl, zip, opcache.
# Novas extensões entram aqui com `install-php-extensions <nome>` quando forem usadas.

# ---------------------------------------------------------------------------
# Desenvolvimento: processos rodam com o UID/GID do usuário do WSL,
# para que vendor/, storage/ e arquivos gerados pertençam a ele.
# ---------------------------------------------------------------------------
FROM base AS development

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN docker-php-serversideup-set-id www-data ${HOST_UID}:${HOST_GID} \
 && docker-php-serversideup-set-file-permissions --owner ${HOST_UID}:${HOST_GID} --service nginx

USER www-data
