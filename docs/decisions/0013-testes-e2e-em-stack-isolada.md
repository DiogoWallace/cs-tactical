# 0013 — Testes E2E com Playwright numa stack isolada e descartável

- Status: Aceita
- Data: 2026-10-17

## Contexto

Até a etapa 1.6 os fluxos completos (cadastro, login, verificação de e-mail, recuperação de senha,
conta) eram validados à mão no navegador. Testes automatizados de ponta a ponta precisam de banco,
fila, e-mail e proxy reais, mas não podem tocar os dados de desenvolvimento nem relaxar as proteções
da aplicação (rate limits, CSRF, Sanctum por sessão, proxies confiáveis). Precisam também rodar
igual na máquina do desenvolvedor e na CI.

## Decisão

1. **Playwright** (`@playwright/test`, versão fixa) com Chromium. Projetos `chromium-desktop`
   (obrigatório na CI) e `chromium-mobile` (testes `@mobile`, local).
2. **Stack própria** (`compose.e2e.yaml` sobre o `compose.yaml`, projeto Compose
   `cs-tactical-e2e`): rede própria, Postgres e Redis em `tmpfs`, banco `cs_tactical_e2e`,
   `APP_ENV=e2e`, sem portas conflitantes. A stack é criada e destruída a cada execução
   (`scripts/e2e.sh`, `make e2e`).
3. **Frontend em build de produção**, a partir de uma cópia somente leitura do código dentro do
   container, para testar o que vai para produção sem interferir no `next dev`.
4. **Mesma origem do uso real**: o container do Playwright compartilha a rede do container web
   (`network_mode: "service:web"`) e acessa `http://localhost:3000`.
5. **Preparo protegido**: `php artisan e2e:prepare` só existe com `APP_ENV=e2e` e confere ambiente,
   driver e `current_database()` = `cs_tactical_e2e` antes de recriar o banco.
6. **Proteções intactas**: nenhum limite ou verificação é desligado para os testes. Cada fluxo que
   altera dados usa uma conta própria; um único cadastro por execução.
7. **Sem retentativas** (`retries: 0`) e **sem esperas fixas**: só esperas condicionais
   (auto-espera do Playwright, `expect.poll` no Mailpit).
8. **Acessibilidade** com `@axe-core/playwright` (WCAG 2.1 A/AA), reprovando violações sérias ou
   críticas; **CSP** verificada nos fluxos reais (ADR 0014).
9. **CI**: job `E2E (Playwright)` em todo PR, publicando relatório, screenshots, vídeos, traces e logs
   da stack como artefato em caso de falha.

## Alternativas consideradas

- **Rodar contra a stack de desenvolvimento**: mais rápido, mas apaga ou mistura dados de
  desenvolvimento e depende do estado local. Recusada (exigência explícita da etapa 1.7).
- **Banco SQLite em memória ou serviços simulados**: não testaria PostgreSQL, Redis, fila e
  e-mail reais, que são justamente onde os fluxos costumam quebrar.
- **Relaxar rate limits no ambiente E2E**: simplificaria os testes, mas deixaria de testar a
  aplicação como ela roda. Recusada.
- **Retentativas na CI**: mascaram falhas e, com dados alterados por execução, nem sempre seriam
  corretas.
- **Cypress**: sem suporte nativo a múltiplos contextos de navegador no mesmo teste, necessário
  para validar o encerramento das outras sessões na troca de senha.

## Consequências

- Uma execução completa leva de 3 a 5 minutos, incluindo o build do frontend; a CI leva ~3,5 min.
- Mudanças em `apps/web/src` só valem na stack E2E depois de recriá-la (`make e2e-down`).
- Novas contas de teste precisam entrar nas duas listas: `PrepareE2eDatabaseCommand.php` e
  `apps/web/e2e/support/accounts.ts`.
- O Next 16 mantém páginas já visitadas escondidas no DOM (`<Activity>`): os testes buscam campos
  só entre os visíveis (`field()` em `e2e/support/auth.ts`).
- Detalhes de uso, depuração e reprodução: [docs/development/e2e.md](../development/e2e.md).
