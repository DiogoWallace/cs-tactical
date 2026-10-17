# Dependências: atualizações e alertas de segurança

Configuração em [`.github/dependabot.yml`](../../.github/dependabot.yml): verificação semanal
(segunda, 06:00, horário de Brasília) de Composer (`apps/api`), pnpm (`apps/web`), GitHub Actions,
Dockerfiles (`infra/docker/api`, `infra/docker/web`) e imagens do `compose.yaml`.

**Não há automerge.** Nenhum PR do Dependabot entra na `main` sem CI verde e revisão.

## Revisar um PR do Dependabot

1. **CI**: os jobs API, Web e E2E precisam passar. Falha → não mesclar; investigar ou fechar o PR.
2. **Tipo de atualização**:
   - *patch/minor agrupados*: ler o changelog dos pacotes de runtime (Laravel, Fortify, Sanctum,
     Next.js, React, Radix). Pacotes só de desenvolvimento pedem menos cuidado.
   - *major*: PR separado. Ler o guia de migração, rodar `make test`, `make lint`, `make e2e` e o
     build localmente antes de aprovar. Se exigir mudanças de código, fechar o PR do Dependabot e
     fazer a atualização numa branch própria.
3. **Pacotes sensíveis à segurança** (autenticação, sessão, criptografia, `next`, `laravel/framework`,
   `laravel/fortify`, `laravel/sanctum`): conferir no changelog se há mudança de comportamento em
   cookies, CSRF, redirecionamentos ou headers. Rodar a sondagem de segurança descrita em
   [fase-1-auditoria.md](fase-1-auditoria.md).
4. **Imagens Docker**: conferir se a tag nova é estável e se mantém a mesma base (Debian/Alpine).
   Postgres e Redis: atualizar só dentro da mesma versão major; major é decisão separada (migração
   de dados).
5. **Depois de mesclar** uma mudança no frontend: reiniciar o `web` de desenvolvimento
   (`docker compose restart web`). Instalar ou mover pacotes com o `next dev` rodando deixa o
   servidor com duas cópias do Next/React (ocorrência registrada na auditoria da Fase 1).

## Alertas de segurança

- Ativar em **Settings → Code security**: *Dependabot alerts* e *Dependabot security updates*
  (disponíveis em repositórios privados no plano gratuito). Configuração do repositório, não do código.
- Alerta **crítico ou alto em dependência de runtime**: corrigir antes de qualquer deploy; abrir PR
  de correção mesmo sem o PR automático.
- Alerta em **dependência só de desenvolvimento** sem correção publicada: registrar como risco
  aceito em [fase-1-auditoria.md](fase-1-auditoria.md), com a justificativa, e revisar a cada
  verificação semanal.
- Verificação manual a qualquer momento:

  ```bash
  docker compose exec api composer audit
  ```

  ```bash
  docker compose exec web pnpm audit --prod
  ```
