# 0004 — Runtime PHP em container (serversideup/php, FPM + Nginx)

- Status: Aceita
- Data: 2026-10-16

## Contexto

O desenvolvimento acontece no WSL2 com Docker Desktop, sem PHP instalado no host. Containers que rodam como root criam arquivos que o usuário do WSL não consegue editar. Usar `php artisan serve` em desenvolvimento e outro servidor em produção gera diferenças de comportamento.

## Decisão

Usar a imagem **`serversideup/php:8.4-fpm-nginx`** como base da API e do worker de filas:

- Nginx e PHP-FPM no mesmo container, servindo na porta interna 8080.
- Roda como usuário não-root (`www-data`), com UID/GID ajustados no build para os do usuário do WSL.
- A mesma imagem base poderá ser usada em produção, mudando só configuração.

## Alternativas consideradas

- **FrankenPHP**: container único e moderno, mas menos convencional e com mais particularidades no modo worker.
- **`php artisan serve`**: simples, mas é um servidor de desenvolvimento, diferente do que roda em produção.
- **Imagens oficiais `php:fpm` + Nginx separado**: dois containers e configuração manual de permissões.

## Consequências

- Arquivos criados pelos containers pertencem ao usuário do WSL.
- Healthcheck nativo da imagem e configuração por variáveis de ambiente.
