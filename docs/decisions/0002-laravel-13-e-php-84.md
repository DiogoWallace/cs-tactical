# 0002 — Laravel 13 com PHP 8.4

- Status: Aceita
- Data: 2026-10-16

## Contexto

O planejamento inicial citava o Laravel 12. O Laravel 13 foi lançado em março de 2026; o Laravel 12 saiu da janela de correção de bugs em agosto de 2026 e recebe apenas correções de segurança até fevereiro de 2027.

## Decisão

Usar **Laravel 13** com **PHP 8.4**. A versão exata do PHP fica fixada na imagem Docker e em `composer.json` (`config.platform.php`).

## Alternativas consideradas

- **Laravel 12**: exigiria uma migração de versão logo nos primeiros meses do produto.
- **PHP 8.5**: suportado, mas 8.4 tem ecossistema de extensões e ferramentas mais testado no momento.

## Consequências

- O produto começa na versão com maior janela de suporte.
- Pacotes de terceiros precisam declarar compatibilidade com o Laravel 13; isso é verificado antes de cada adoção.
