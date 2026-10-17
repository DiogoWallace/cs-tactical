# Testes E2E (Playwright)

Testes de ponta a ponta no navegador (Chromium), contra a aplicação completa: Next.js (build de
produção), API Laravel, worker de filas, PostgreSQL, Redis e Mailpit.

```bash
make e2e
```

Sobe uma stack Docker **isolada e descartável**, recria o banco E2E, roda o Playwright e derruba
tudo no final. A primeira execução baixa a imagem do Playwright (~1 GB) e faz o build de produção
do frontend (alguns minutos).

## Isolamento: o que garante que nada toca o banco de desenvolvimento

| Camada | Proteção |
| --- | --- |
| Compose | Projeto próprio (`-p cs-tactical-e2e`, `compose.e2e.yaml` sobre o `compose.yaml`): containers, rede (`cs-tactical-e2e-internal`, 172.31.251.0/24) e volumes separados. A stack E2E não alcança o Postgres de desenvolvimento. |
| Dados | Postgres e Redis em `tmpfs`: somem quando a stack cai. Banco `cs_tactical_e2e`. |
| Comando | `php artisan e2e:prepare` só é registrado com `APP_ENV=e2e`, e se recusa a rodar se o ambiente não for `e2e` ou se a conexão não for PostgreSQL no banco `cs_tactical_e2e` (confere a configuração e o `current_database()` do servidor). Testado em `tests/Feature/System/PrepareE2eDatabaseCommandTest.php`. |
| Portas | Nenhuma porta conflita com a stack de desenvolvimento. Só o Mailpit é publicado (`127.0.0.1:8026`, para depuração). |
| Frontend | O container `web` copia o código para dentro dele e roda `next build && next start`: não escreve em `apps/web` (onde o `next dev` continua rodando). |
| Academy | Conteúdo próprio dos testes (`apps/web/e2e/fixtures/academy`, montado só leitura), importado pelo `scripts/e2e.sh` com `academy:import`. As imagens vão para um `tmpfs` em `storage/app/academy`: nada é gravado na pasta da API, compartilhada com a stack de desenvolvimento. Mudar o conteúdo real (`content/academy`) não afeta os testes. |

As duas stacks podem ficar no ar ao mesmo tempo.

## Limites de segurança continuam ativos

Nada é relaxado para os testes: mesmos limites de requisição (login 5/min por e-mail+IP, cadastro
3/min por IP, redefinição 5/min, reenvio de verificação 6/min), proxies confiáveis (só o IP fixo do
`web` da stack E2E), cookies de sessão HTTP-only e Sanctum por sessão. Por isso:

- cada fluxo que altera dados usa a própria conta (`e2e/support/accounts.ts`, criadas pelo
  `e2e:prepare` com a mesma lista);
- há um único cadastro por execução;
- **não há retentativas** (`retries: 0`): um banco é criado por execução e os fluxos mudam senha e
  e-mail; repetir um teste mascararia falhas e esbarraria nos limites.

## Topologia: mesma origem

O container do Playwright usa `network_mode: "service:web"`: compartilha a rede do container do
Next.js. Assim o navegador acessa `http://localhost:3000`, a mesma origem do uso real (cookies
host-only, `SANCTUM_STATEFUL_DOMAINS=localhost:3000`, proxy `/api` do Next para a API), e ainda
resolve `mailpit` pelo DNS da rede da stack. Validado na prática (`e2e/specs/smoke.spec.ts`).

## Esperas

Nenhum `sleep` ou `waitForTimeout`. A sincronização usa as esperas automáticas do Playwright
(`expect(...).toBeVisible()`, `toHaveURL`) e, para e-mails, `expect.poll` na API do Mailpit
(`e2e/support/mailpit.ts`). A caixa de entrada é "aberta" antes da ação que envia o e-mail, e
`next()` só aceita mensagens que não existiam naquele momento.

## Projetos

| Projeto | Dispositivo | Testes | Onde roda |
| --- | --- | --- | --- |
| `chromium-desktop` | Desktop Chrome | todos, menos `@mobile` | local e CI (obrigatório) |
| `chromium-mobile` | Pixel 7 | só `@mobile` (menu do celular, aula e catálogo da Academy) | local |

## O que é coberto

| Spec | Fluxos |
| --- | --- |
| `registration` | cadastro → painel com aviso de e-mail não verificado → e-mail de confirmação |
| `login` | senha errada (mensagem genérica); painel com Academy e Teams só informativos; logado no `/login` e `/cadastro` vai ao painel; "manter conectado" cria cookie de 30 dias, sem ele não cria |
| `session` | rota protegida sem cookie e com cookie inválido → login com `next`; volta ao destino com a query; 404 da área logada; sair: voltar no navegador não reabre a área logada e o cookie antigo não vale mais |
| `redirects` | `next` inseguro (`//evil.example`, `https://evil.example`, `/\evil.example`, `javascript:`, tab e quebra de linha) nunca sai da aplicação; `next` interno é respeitado com a query |
| `email-verification` | aviso + reenvio; link adulterado recusado; link sem sessão → login → volta e confirma |
| `password-reset` | mesma resposta com e sem conta; token no fragmento e fragmento removido da URL; redefinição; link não reutilizável; senha antiga recusada e nova aceita |
| `account` | perfil atualiza o apelido no cabeçalho; troca de e-mail com link no novo endereço e aviso no antigo; troca de senha mantém a sessão atual e encerra as outras |
| `mobile-nav` (`@mobile`) | menu lateral: abre, Esc fecha e devolve o foco, navega, marca a página atual, sai |
| `accessibility` | axe (WCAG 2.1 A/AA) nos temas escuro e claro: home, `/status`, login, cadastro, esqueci-senha, redefinir-senha (formulário e link inválido), painel, conta, 404, verificar-email e confirmar-email (estados de erro); falha em violações sérias ou críticas (todas vão anexadas ao relatório). Navegação por teclado no login e foco visível |
| `security-headers` | headers de segurança das páginas (build de produção) e das respostas da API pelo proxy ([seguranca-http.md](../architecture/seguranca-http.md)) |
| `csp` | nenhuma violação da CSP aplicada nos fluxos principais; violações da CSP Report-Only anexadas ao relatório |
| `academy` | catálogo com cursos publicados, capa pela rota de mídia e filtro por nível (inclusive vazio); curso com módulos e aulas públicos em ordem; aula com Markdown, imagem, callout e quiz (sem gabarito no HTML) e navegação anterior/próxima entre módulos; vídeo só depois do clique, pelo `youtube-nocookie.com` (interceptado: sem internet); 404 com `noindex` para rascunho, ancestral em rascunho, aula de outro curso e slug inválido; CSP com o player só nas aulas e sem violações; axe nos dois temas; teclado; `@mobile`: lista de aulas recolhível e nenhuma rolagem horizontal |

Total: 46 testes (43 desktop, 3 mobile).

## Executar só uma parte

Argumentos depois de `a=` vão para o `playwright test`:

```bash
make e2e a="--project=chromium-desktop"
```

```bash
make e2e a="e2e/specs/login.spec.ts"
```

```bash
make e2e a="--grep 'troca de senha'"
```

## Depurar

- **Relatório HTML** da última execução, com screenshots, vídeos e traces das falhas:
  `make e2e-report` e abra http://localhost:9323.
- **Trace** de um teste: no relatório, ou
  `docker compose run --rm --no-deps web ./node_modules/.bin/playwright show-trace <caminho>/trace.zip`.
- **Logs da stack** numa falha: `apps/web/e2e/.results/stack.log`.
- **Manter a stack no ar** para investigar: `E2E_KEEP=1 ./scripts/e2e.sh`. Os e-mails ficam em
  http://localhost:8026. Para rodar de novo sem recriar a stack:

  ```bash
  docker compose -p cs-tactical-e2e -f compose.yaml -f compose.e2e.yaml exec -T api php artisan e2e:prepare
  ```

  ```bash
  docker compose -p cs-tactical-e2e -f compose.yaml -f compose.e2e.yaml --profile e2e-runner run --rm --no-deps playwright npx playwright test
  ```

  Depois: `make e2e-down`. Mudanças em `apps/web/src` só valem depois de recriar o `web`
  (`make e2e-down` e `make e2e`): ele roda um build de produção.

## Reproduzir uma falha da CI

1. Baixe o artefato `e2e-report` da execução (aba **Summary** do workflow). Ele traz
   `.report/` (relatório HTML), `.results/` (screenshots, vídeos, traces) e `stack.log`.
2. Rode localmente o mesmo comando da CI, na mesma branch:
   ```bash
   CI=1 ./scripts/e2e.sh --project=chromium-desktop
   ```
   `CI=1` usa a mesma configuração da CI (2 workers, `test.only` proibido).

## CI

Job `E2E (Playwright)` em `.github/workflows/ci.yml`: roda `scripts/setup.sh` e
`scripts/e2e.sh --project=chromium-desktop` em todo PR. Em falha, publica relatório, screenshots,
traces e logs como artefato (`e2e-report`, 14 dias).

Para ser **obrigatório para o merge**, o check `E2E (Playwright)` precisa ser marcado como
obrigatório na proteção da branch `main` (Settings → Branches/Rulesets → Require status checks).
Essa configuração é do repositório no GitHub, não do código.
