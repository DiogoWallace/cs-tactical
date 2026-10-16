# 0001 — Monorepo com monólito modular e frontend desacoplado

- Status: Aceita
- Data: 2026-10-16

## Contexto

O CS Tactical tem dois pilares (Academy e Teams) que precisam ser integrados: um treinador atribui conteúdos da Academy aos jogadores da equipe e acompanha o progresso. O time é pequeno e o produto está no início.

## Decisão

- Um único repositório com `apps/api` (Laravel) e `apps/web` (Next.js).
- Backend como **monólito modular**: um único deploy, com o código organizado por domínio em `app/Modules/<Modulo>`.
- Frontend desacoplado, consumindo a API REST versionada (`/api/v1`).
- Sem ferramenta de orquestração de monorepo (Turborepo, Nx): um `Makefile` na raiz centraliza os comandos.
- Módulos, tabelas e abstrações só são criados quando a primeira funcionalidade que os usa for implementada.

## Alternativas consideradas

- **Microsserviços**: custo operacional alto (deploys, rede, consistência) sem benefício nesta escala.
- **Dois repositórios**: mudanças que tocam API e frontend ficariam em PRs separados; mais difícil manter contrato e documentação alinhados.
- **Turborepo/Nx**: úteis com vários pacotes JS compartilhados. Aqui há só uma aplicação JS.

## Consequências

- Um PR pode alterar API, frontend e documentação juntos.
- As fronteiras entre módulos dependem de disciplina (ver regras em `docs/architecture/visao-geral.md`); testes de arquitetura do Pest ajudam a impô-las.
- Se um módulo precisar escalar separadamente no futuro (ex.: análise de demos), ele pode ser extraído, pois já está isolado por pasta.
