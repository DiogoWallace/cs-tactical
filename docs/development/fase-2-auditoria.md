# Auditoria de fechamento da Fase 2 (etapa 2.8)

Branch `docs/phase-2-audit` sobre a `main` em `f90af7b`, 2026-10-24. Escopo: módulos `Academy` e
`Learning` (catálogo, importação, aulas, matrícula, progresso, quizzes), integração com o painel,
segurança, acessibilidade, desempenho, dependências, testes, cobertura, documentos e ADRs, e a
situação dos bloqueios de produção. Base: as subetapas da [2.7](fase-2-etapa-2.7-plano.md) e as
verificações refeitas nesta etapa ([Testes executados](#testes-executados)). O relatório por etapa
está em [fase-2-relatorio.md](fase-2-relatorio.md).

## Conclusão

| | Situação |
| --- | --- |
| **Fase 2 para desenvolvimento** | **Concluída e validada**, com uma verificação manual pendente: leitor de tela com NVDA (X3, decisão R7), a cargo do responsável. Nenhum achado alto ou crítico em aberto; todos os testes passam. |
| **Produção** | **Continua bloqueada** por P1–P4 e G1 ([bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md)), que a Fase 2 não mudou. Recomendação nova para a imagem de produção: OPcache ligado ([desempenho.md](desempenho.md#ambiente)). |

Dependências para iniciar a 2.8 ([plano da 2.7](fase-2-etapa-2.7-plano.md#dependências-para-iniciar-a-28)):

| # | Condição | Situação |
| --- | --- | --- |
| 1 | 2.7a–2.7d na `main` com CI verde | sim: PRs #30, #31, #40 e #41; CI da `main` verde em `f90af7b` |
| 2 | Achado crítico ou alto corrigido ou aceito | não houve achado crítico ou alto na Fase 2 |
| 3 | Linha de base de desempenho e limites aprovados | aprovados em 2026-10-24 ([desempenho.md](desempenho.md#limites-de-regressão-aprovados-em-2026-10-24)) |
| 4 | Investigação do `academy-public` (R1) concluída e solução aprovada, ou adiada com mitigação registrada | investigação concluída; alarme aprovado e implementado (PR #38); isolamento (alternativa H) adiado, com a mitigação registrada |

## Achados

Severidade na escala da Fase 1: crítica, alta, média, baixa, informativa. "Origem" aponta onde o
achado foi registrado com a evidência completa.

| ID | Severidade | Achado | Origem / evidência | Situação |
| --- | --- | --- | --- | --- |
| E1 | Média | Laço `/painel` ↔ `/login` para quem usa "manter conectado": com a sessão expirada, o proxy do Next olhava só o cookie de sessão e o login aceitava o cookie de lembrar; terminava em 429 | Reproduzido na stack E2E (26 navegações em 4 s); [autenticacao.md](../architecture/autenticacao.md#5-proteção-de-rotas-no-next) | **Corrigido** (PR #39): o proxy renova a sessão pela API e entrega o cookie novo; 429 no login mostra o formulário; E2E de regressão (falha com o proxy antigo) |
| E2 | Média | `/me/enrollments` com custo matrículas × aulas: 162 ms em processo e 173 ms HTTP p50 com 20 matrículas e 800 aulas | 2.7d, achado D1 ([desempenho.md](desempenho.md#achados)) | **Corrigido** (PR #41): 72–83 ms em processo, 93–106 ms HTTP p50; mesmas consultas; teste com contraprova |
| E3 | Baixa | Respostas da API com `Cache-Control: no-cache, private`: o navegador podia gravar o JSON de progresso e tentativas | 2.7b, achado A1 | **Corrigido** (PR #38): `no-store, private` em toda resposta sob `/api/*`, inclusive erros; sondagem S4.1 confirma |
| E4 | Baixa | Esqueletos de carregamento pulsavam com `prefers-reduced-motion: reduce` | 2.7c, defeito D1 | **Corrigido** (PR #40), com teste nas duas preferências |
| E5 | Baixa (testes) | Limite `academy-public` compartilhado por todos os testes na stack E2E (a chave é o IP, e todos os navegadores saem do mesmo lugar) | 2.7b, S9 e R1 | **Mitigado**: alarme ao fim de todo `make e2e` (PR #38). Pico subindo: 56 (2026-10-18) → 73–81/120 (2026-10-24); alerta em 90. Isolamento (H) adiado |
| E6 | Baixa (testes) | Teste de acessibilidade da aula falhou uma vez na CI por contraste de botões primários e passou na reexecução e em 4 repetições locais | CI do PR #38, primeira tentativa | **Registrado** como instabilidade (família do K7), causa não provada; se repetir, examinar o artefato do axe (transição de cor dos botões) |
| E7 | Informativa | O bloco `image` usa `style` inline com números validados: viola só a CSP Report-Only (`style-src-attr`) | 2.7b, achado A2 | **Aceito**; tratar junto com a CSP por nonce/hash |
| E8 | Informativa | CSP aplicada com `'unsafe-inline'` também nas páginas da Academy | F7 da Fase 1; [seguranca-http.md](../architecture/seguranca-http.md#conteúdo-editorial-da-academy-etapa-23-com-unsafe-inline) | **Aceito**: o conteúdo é editorial (arquivos do repositório), validado na importação e renderizado sem HTML; sem violação da CSP aplicada nos fluxos (S6). Reavaliar antes de qualquer conteúdo vindo de usuário |
| E9 | Informativa | Custo restante do `/me/enrollments` linear nas aulas dos cursos matriculados (hidratação Eloquent); envio de tentativa com uma inserção por resposta; subconsultas correlacionadas do catálogo | 2.7d, hipóteses H1–H3 | **Registrado**; só com medição que justifique |
| E10 | Informativa | 404 das páginas da Academy com status 200 e `noindex` (o stream do PPR já começou) | 2.3, ajuste C6 | **Aceito** (comportamento do Next 16) |
| E11 | Informativa | Foco dos cartões de curso indicado por sublinhado e borda, sem contorno próprio | 2.7c, X1/X2 (O1) | Melhoria opcional, não aplicada |
| F6 | — | `braces` (Fase 1, aceitação temporária a revisar no fechamento da Fase 2) | `pnpm audit` completo e `--prod` | **Encerrado**: o audit não aponta mais nenhuma vulnerabilidade; o pacote continua só em desenvolvimento (`pnpm why braces`) |
| F11 / G1 | Média (governança) | `main` sem proteção | API do GitHub: `Branch not protected` (404) | **Inalterado**, pendente de decisão do responsável |

## Verificação manual pendente

**X3, leitor de tela (R7).** A decisão R7 pede NVDA na auditoria manual da 2.7c, com navegador,
versões, cenários, resultados e limitações registrados; não bloqueia a CI. A parte automatizada
(axe WCAG 2.2 AA em 56 combinações, teclado, 320 px, movimento reduzido) foi feita e está limpa. Até
o registro do NVDA, a acessibilidade com leitor de tela é **não verificada**, não "aprovada".

## Sondagem de segurança

`scripts/security-probe.sh` (2.7b, R6) refeito nesta etapa, na stack E2E nova e depois de todos os
merges da fase: **41/41**. Cobre: leituras sem sessão (401), escritas sem token CSRF ou com token de
outra sessão (419), cookie adulterado (401), acesso à tentativa e às listas de outro jogador (404),
caminhos e UUIDs manipulados (404), nota e aprovação do cliente ignoradas, envio repetido (409),
seis envios malformados (422, tentativa continua aberta), corpo não JSON (422), esgotar (409) e
revisão liberada (R20), `Cache-Control: no-store, private` nas respostas autenticadas, logout
(cookie antigo → 401) e **nenhum 5xx em 49 requisições**.

Matriz de rotas (2.7b, S1): 26 rotas; as 10 sem sessão são exatamente a lista pública do
`RouteAuthorizationTest`; toda escrita tem limite (`learning-write` 30/min, `quiz-attempt` 10/min
por usuário). Matriz de autorização de [academy.md](../architecture/academy.md#matriz-de-autorização)
com teste por linha e papel.

## Headers e configuração

Na stack E2E (build de produção do Next):

- `/login` e `/academy`: CSP aplicada, `X-Frame-Options: DENY`, `nosniff`,
  `strict-origin-when-cross-origin`, `Cache-Control: private, no-cache, no-store`; sem HSTS e sem
  `X-Powered-By`. As aulas acrescentam só `frame-src https://www.youtube-nocookie.com` (ADR 0019).
- `/api/v1/academy/courses` pelo proxy: CSP `default-src 'none'`, `DENY`, `nosniff`,
  `no-referrer`, `Cache-Control: no-store, private`; sem HSTS (responsabilidade da borda, P1).
- `system:check-production-config` na stack E2E: 7 itens falham (`APP_ENV`, `APP_DEBUG`, cookie
  `Secure`, URLs sem HTTPS, domínios stateful, `LOG_LEVEL`), como esperado fora de produção; os
  itens de proxy confiável (P3) passam.

## Dependências

| Verificação | Resultado |
| --- | --- |
| `composer audit` | nenhuma vulnerabilidade |
| `pnpm audit` (completo) e `pnpm audit --prod` | nenhuma vulnerabilidade |
| Desatualizados | sem aviso de segurança: `laravel/framework` 13.38.0 e `larastan` 3.13.2 (PR #27 do Dependabot verde, aguardando decisão), `next`/`eslint-config-next` 16.4.3, `react-dom` 19.3.1, `geist` 1.8.2; majors de Pest 5, PHPUnit 13, ESLint 10, TypeScript 7 e `@types/node` para PRs dedicados |
| PRs do Dependabot abertos | #26 (Mailpit, verde) e #27 (Laravel, verde) aguardando decisão; #28 (imagem PHP 8.5) em espera: conflita com a ADR 0002 (PHP 8.4) e com o `setup-php` 8.4 da CI |

## Desempenho

Linha de base aprovada em [desempenho.md](desempenho.md) (`make perf`, stack descartável, cenário
pequeno e ampliado de 2.000 aulas): contagem de consultas igual nos dois cenários em todos os
endpoints; nenhuma consulta acima de 3 ms no ampliado; um problema comprovado e corrigido (E2).
E2E na CI: máximo de 11,3 s por teste (limite 30 s).

## Testes executados

Verificação final desta etapa, na `main` em `f90af7b` (e CI da `main` no mesmo commit):

| Verificação | Resultado |
| --- | --- |
| Pest (`make test-api`) | **743 passaram** (3.420 asserções) |
| Concorrência (`make test-concurrency`) | **5 passaram** (último admin; matrícula, conclusão e tentativas simultâneas) |
| Vitest | **307 passaram** (34 arquivos) |
| Lint (`make lint`: Pint, Larastan, ESLint, Prettier, TypeScript) | sem erros |
| Conteúdo real (`academy:import --dry-run`) | 0 erros, nada gravado |
| Playwright (`make e2e`, stack nova) | **61 passaram** (57 desktop, 4 mobile); alarme `academy-public`: pico 76/120, 0 × 429. CI: 57 desktop |
| Sondagem de segurança (`scripts/security-probe.sh`) | **41/41** |
| Cobertura (`make coverage`) | API **95,4%** linhas (2.365/2.479), **86,9%** métodos (298/343); frontend **78,7%** linhas, 73,9% funções, 75,4% branches, 77,6% statements |
| Desempenho (`make perf`) | uma execução antes e duas depois da correção E2; limites aprovados |

Evolução na Fase 2 (CI da `main`, do PR #12 ao #41): Pest 326 → 743, Vitest 105 → 307, E2E
desktop 33 → 57 (mais 4 no celular), concorrência na CI desde o PR #21 (3 → 5). Cobertura da API:
93,8% → 95,4% das linhas (a de métodos caiu de 92,2% para 86,9%, com 241 métodos novos); do
frontend: 71,6% → 78,7% das linhas.

## Documentos e ADRs

- **ADRs da fase**: 0015–0019, aceitas, conferidas com o código na 2.7a (divergências DV1–DV4
  corrigidas) e nas revisões de cada etapa. A 0020 está reservada para a busca com Elasticsearch
  (fora da Fase 2).
- **Propostas fora do escopo da fase**: ADR 0021 (CS Tactical Training) e ADR 0022 (análise de
  partidas por demos), só planejamento, nada implementado.
- **Documentos novos**: [academy.md](../architecture/academy.md),
  [academy-learning.md](../database/academy-learning.md), [academy-v1.md](../api/academy-v1.md),
  [conteudo-importavel.md](../academy/conteudo-importavel.md), [fase-2-plano.md](fase-2-plano.md),
  [fase-2-etapa-2.7-plano.md](fase-2-etapa-2.7-plano.md), [desempenho.md](desempenho.md), este
  documento e o [relatório](fase-2-relatorio.md).
- **Atualizados**: [autenticacao.md](../architecture/autenticacao.md) (renovação da sessão),
  [seguranca-http.md](../architecture/seguranca-http.md) (CSP das aulas, `no-store`),
  [e2e.md](e2e.md) (61 testes, alarme), [testes-de-concorrencia.md](testes-de-concorrencia.md)
  (Learning e quizzes; CI), README.

## Preparação para produção

Nada foi implantado. P1–P4 e G1 continuam abertos, sem mudança de situação nesta fase. Itens da
Fase 2 a levar para a configuração de produção (complementam P4, sem virar bloqueio novo):

- imagem da API com **OPcache ligado** (sem ele, ~100 ms a mais por requisição);
- disco `academy` persistente para as imagens importadas (`storage/app/academy`) e o conteúdo de
  `content/academy` disponível para o `academy:import`;
- limites `academy-public` (120/min) e `academy-media` (600/min) por IP real, o que depende de P1.
