# Imagem da API

- Base: `serversideup/php:8.4-fpm-nginx` (Nginx + PHP-FPM, porta interna 8080).
- Alvo `development`: ajusta o usuário `www-data` para `HOST_UID`/`HOST_GID` do `.env`.
- O mesmo build serve os serviços `api` (Nginx + FPM) e `worker` (`php artisan queue:work`).
- Configuração de PHP por variáveis de ambiente (`PHP_*`), definidas no `compose.yaml`.
