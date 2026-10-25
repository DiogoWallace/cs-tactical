# Relatório da Fase 2 — Academy

Período: 2026-10-17 a 2026-10-24. Módulos `Academy` (catálogo, importação, aulas) e `Learning`
(matrícula, progresso, quizzes), integração com o painel e revisão transversal (2.7). Decisões de
produto P1–P8 e ajustes de cada etapa em [fase-2-plano.md](fase-2-plano.md); auditoria de
fechamento em [fase-2-auditoria.md](fase-2-auditoria.md).

Números de testes desta página: CI da `main` (desktop) depois de cada merge, ou do PR quando o run
da `main` foi cancelado pela concorrência.

| Etapa | PR (merge) | Pest | Vitest | E2E desktop |
| --- | --- | --- | --- | --- |
| início da fase | #12 (`2a543fb`) | 326 | 105 | 33 |
| 2.0 Desenho | #14 (`2d3ef45`) | — | — | — |
| 2.1 Catálogo e importador | #15 (`9ef714d`), #16 (`c11ccd5`) | 469 | 105 | 33 |
| 2.2 API do catálogo | #17 (`9d50164`) | 606 | 105 | 33 |
| 2.3 Telas | #18 (`06ed1a1`) | 606 | 246 | 43 |
| 2.4 Learning | #20 (`26ae196`), #21 (`f002dbd`), #22 (`f1c9aec`) | 694 | 271 | 46 |
| 2.5 Quizzes | #23 (`77c97f5`) | 738 | 283 | 48 |
| 2.6 Painel | #24 (`5bc9f3f`) | 738 | 298 | 53 |
| 2.7 plano | #25 (`15eecea`) | — | — | — |
| 2.7a Documentação e cobertura | #30 (`2d12c48`) | 739 | 298 | 54 |
| 2.7b Segurança | #31 (`3ead0a2`), #38 (`1c34f33`) | 742 | 298 | 54 |
| Correção da sessão | #39 (`357c8a8`) | 742 | 307 | 55 |
| 2.7c Acessibilidade | #40 (`bea06da`) | 742 | 307 | 57 |
| 2.7d Desempenho | #41 (`f90af7b`) | 743 | 307 | 57 |
| 2.8 Auditoria | este PR | 743 | 307 | 57 (61 com o celular) |

Testes de concorrência na CI desde o #21: 3 (último admin, matrícula, conclusão), 5 depois da 2.5.

## Etapa 2.0 — Desenho

PR #14. Só documentos, nenhum código.

- **Arquivos**: ADRs 0015–0019; [academy-learning.md](../database/academy-learning.md),
  [academy.md](../architecture/academy.md), [academy-v1.md](../api/academy-v1.md),
  [conteudo-importavel.md](../academy/conteudo-importavel.md), [fase-2-plano.md](fase-2-plano.md).
- **Decisões**: catálogo público e estudo com login; P1–P8 (aula arquivada não é lida, revisão do
  quiz não reinicia tentativas, estudar sem e-mail confirmado, sem cancelar matrícula, conteúdo
  no repositório, tudo ou nada na múltipla escolha, gabarito só depois de aprovar ou esgotar, só
  YouTube); imagens gravadas antes da transação, por hash.
- **Pendências**: nenhuma; a revisão de coerência corrigiu as inconsistências antes da 2.1.

## Etapa 2.1 — Catálogo no banco e importador

PRs #15 e #16.

- **Arquivos**: módulo `apps/api/app/Modules/Academy` (46 arquivos: models, enums, escopo
  `visible()`, schemas dos blocos v1, importador em três fases, `academy:media-prune`), 7
  migrations, `content/academy` (curso de exemplo, `_fixtures/blocks-v1.json`), disco `academy`.
- **Decisões** (A1–A8 no plano): quiz com `retired_at` e único parcial; slugs únicos adiáveis;
  `media/{sha[0:2]}/{sha}.{ext}`; `content/` montado só leitura; `symfony/yaml` estrito; contrato
  `QuizUsage`; a P5 virou um teste Pest que importa o conteúdo real em toda execução.
- **Testes**: banco (checks, únicos, FKs), visibilidade 3×3×3, importador (idempotência, `--dry-run`,
  `--archive-missing`, lock), imagens (tipo pelo conteúdo, falha simulada na transação sem
  referência quebrada, órfãos), blocos e quizzes. Pest 326 → 469.
- **Pendências**: recuperação do bind mount do conteúdo documentada no PR #16.

## Etapa 2.2 — API do catálogo

PR #17.

- **Arquivos**: rotas, `CatalogQuery`, `LessonPage`, `EditorialAccess`, controllers sem acesso ao
  banco (teste de arquitetura), resources com lista fechada de campos, `MediaResponse`.
- **Decisões** (B1–B7): mídia em `/api/v1/academy/media/{sha256}.{ext}`; só imagem referenciada por
  conteúdo público, sempre `no-store`; limite próprio `academy-media` (600/min); `level` e `page`
  validados; `links` relativos.
- **Testes**: `CatalogApiTest` e `MediaApiTest` (matriz pelo HTTP, papéis × `include_unpublished`,
  ausência de gabarito e de campos internos, contagem de consultas 3/4/6, 429). Pest 469 → 606.

## Etapa 2.3 — Telas do catálogo e da aula

PR #18.

- **Arquivos**: `apps/web/src/features/academy` (leitura no servidor com Zod, espelho dos blocos,
  `SafeMarkdown`, um componente por bloco, telas e estados), rotas `/academy`, curso e aula,
  conteúdo de E2E em `apps/web/e2e/fixtures/academy`.
- **Decisões** (C1–C9): `react-markdown` sem HTML nem GFM; vídeo só depois do clique, em
  `youtube-nocookie`; `<img>` direto da API (sem `next/image`); CSP com `frame-src` só nas aulas;
  404 com status 200 e `noindex` (PPR); modo editorial fora das telas.
- **Testes**: Vitest 105 → 246 (schemas com as fixtures compartilhadas, blocos, telas); E2E 33 → 43
  (catálogo, filtro, curso, aula, vídeo interceptado, 404, CSP, axe nos dois temas, teclado,
  celular).
- **Pendências**: CSP com `'unsafe-inline'` (aceita, E8 da auditoria); limite `academy-public`
  compartilhado no E2E (E5).

## Etapa 2.4 — Learning: matrícula, conclusão e progresso

PRs #20, #21 (testes de concorrência na CI) e #22 (espera do painel no E2E).

- **Arquivos**: módulo `apps/api/app/Modules/Learning` (`EnrollInCourse`, `CompleteLesson`,
  `PublicContentLocks`, `ProgressQuery`, Policies), migrations `enrollments` e `lesson_progress`,
  frontend `apps/web/src/features/learning` e a página "Meu progresso".
- **Decisões** (L1–L9): progresso calculado na leitura; campos aditivos em `/progress`; histórico de
  curso arquivado sem percentual; `last_activity_at` só com atividade nova; Zod `jitless` por causa
  da CSP; concorrência testada com dois processos reais.
- **Testes**: Pest 606 → 694, Vitest 246 → 271, E2E 43 → 46, concorrência na CI.
- **Pendências**: cabeçalho público mostrando "Entrar" a quem estava logado (resolvido na 2.6);
  percentual calculado a cada leitura (medido na 2.7d: E2).

## Etapa 2.5 — Quizzes

PR #23.

- **Arquivos**: `StartQuizAttempt`, `SubmitQuizAttempt`, `QuizStanding*`, `QuizAttemptPresenter`,
  `AttemptsQuizUsage`, migrations `quiz_attempts` e `quiz_attempt_answers`, `QuizPanel` no
  frontend.
- **Decisões** (Q1–Q7): esgotar = todas as tentativas enviadas; nota mínima no snapshot; tentativa
  aberta de quiz aposentado ainda pode ser enviada; quiz desconhecido → lista vazia; resultado
  antes da liberação sem gabarito; trava da matrícula serializa as tentativas.
- **Testes**: Pest 694 → 738, Vitest 271 → 283, E2E 46 → 48, concorrência 3 → 5.
- **Pendências**: acerto/erro por pergunta permite eliminar opções (compromisso da ADR 0018).

## Etapa 2.6 — Painel e integração

PR #24. Só frontend.

- **Arquivos**: `get-session-state.ts`, `session-header.tsx`, `SiteHeader` com sessão desconhecida,
  `academy-dashboard-card.tsx`, painel e página inicial.
- **Decisões** (D1–D7): cabeçalho escolhido no servidor, sem duplicar páginas; "continuar
  estudando" pela última atividade registrada; números só com o que a API devolve; seis estados do
  cartão; esqueleto neutro no cabeçalho.
- **Testes**: Vitest 283 → 298, E2E 48 → 53 (`navigation`).
- **Pendências**: páginas públicas com sessão fazem um `/me` a mais (medido na 2.7d).

## Etapa 2.7 — Revisão transversal

Plano no PR #25 ([fase-2-etapa-2.7-plano.md](fase-2-etapa-2.7-plano.md)), decisões R1–R7, uma
subetapa por PR.

### 2.7a — Documentação e cobertura (PR #30)

- Divergências DV1–DV4 corrigidas (`e2e.md`, `academy.md`, plano, comentário do cartão).
- E2E do histórico de curso arquivado (fixture `historico-e2e`, `e2e:prepare --seed-history`) e
  reforço do teste do gabarito depois de esgotar, ambos com contraprova.
- Pest 738 → 739, E2E 53 → 54.

### 2.7b — Segurança (PRs #31 e #38)

- Sondagem versionada (`scripts/security-probe.sh`): 41/41. Matriz de rotas e de autorização,
  CSRF real, shell estático sem dados de sessão, limites, CSP estendida à Academy, dependências.
- Achados: A1 (`no-cache` → `no-store`, corrigido no #38), A2 e A3 informativos.
- R1: investigação do `academy-public` compartilhado no E2E; alarme de pico ao fim de todo
  `make e2e` (#38); isolamento (H) adiado.
- Pest 739 → 742.

### Correção fora do plano: sessão com "manter conectado" (PR #39)

Laço `/painel` ↔ `/login` encontrado no uso real (E1 da auditoria): o `proxy.ts` agora renova a
sessão pela API quando só o cookie de lembrar existe; 429 no login mostra o formulário. Testes
Vitest do proxy e do `RedirectIfAuthenticated` (298 → 307) e E2E de regressão (54 → 55).

### 2.7c — Acessibilidade (PR #40)

- axe WCAG 2.2 AA em 56 combinações (páginas × sessão × tema × 1280/320 px): zero violações;
  teclado e 320 px sem problemas.
- Defeito D1 (esqueletos com movimento reduzido) corrigido, com teste nas duas preferências
  (E2E 55 → 57).
- Pendente: NVDA (X3), manual. Opcional: O1.

### 2.7d — Desempenho (PR #41)

- `make perf` ([desempenho.md](desempenho.md)): stack descartável, cenário pequeno e ampliado
  (2.000 aulas), consultas e `EXPLAIN` por endpoint, tempos HTTP da API e das páginas.
- Contagem de consultas igual nos dois cenários; nenhuma consulta acima de 3 ms.
- D1 (`/me/enrollments` matrículas × aulas) corrigido: 162 → 72–83 ms em processo. Teste com
  contraprova (Pest 742 → 743).
- Limites de regressão aprovados: consultas exatas, HTTP p50 + 30%, E2E 30 s.

## Etapa 2.8 — Auditoria e fechamento

Este PR: [fase-2-auditoria.md](fase-2-auditoria.md) e este relatório. Verificações refeitas na
`main` em `f90af7b`: Pest 743, concorrência 5, Vitest 307, lint, simulação do conteúdo real,
E2E 61/61 em stack nova, sondagem 41/41, cobertura (API 95,4% das linhas, frontend 78,7%), audits
de dependências limpos (encerra a aceitação do `braces` da Fase 1).

## Outras entregas no período (fora do escopo da Fase 2)

- Correções de E2E com as cópias escondidas do Next 16 (`<Activity>`): #11 (texto) e #35
  (`data-testid`), com os helpers visíveis `text()`, `field()` e `testId()`.
- CI e dependências: #9, #10, #13 (runners `ubuntu-24.04`), #37 (grupo do frontend, Dependabot).
- Planejamento de iniciativas futuras, sem código de produto: #19 (CS Tactical Training,
  ADR 0021), #32 (fontes de telemetria do CS2), #33, #34 e #36 (análise de partidas por demos,
  ADR 0022, e plano do vínculo com a Steam).

## Decisões do responsável registradas durante a fase

- P1–P8 (2.0), B1–B3 (mídia, 2.2), R1–R7 (plano da 2.7).
- A1: aplicar `no-store` (2026-10-24). R1: alarme sem mudar nenhum controle; alternativa A
  (`X-Forwarded-For` do Playwright) recusada; isolamento H só com aprovação específica.
- Limites de regressão de desempenho aprovados (2026-10-24).
- PR #28 do Dependabot (PHP 8.5) em espera até a decisão sobre a versão do PHP (ADR 0002).

## Riscos que permanecem

| Risco | Situação |
| --- | --- |
| Produção bloqueada por P1–P4 | inalterado; borda, proxies confiáveis e configuração segura ainda por implantar |
| G1 (`main` sem proteção) | inalterado; regras valem só por processo |
| Leitor de tela não verificado (X3) | pendente do responsável |
| Limite `academy-public` compartilhado no E2E | alarme ativo; pico subiu para 73–81/120 (alerta em 90); isolamento H quando disparar |
| E2E instável sob carga (K7) e o contraste da aula (E6) | sem retentativas por decisão; diagnosticar a cada falha |
| CSP com `'unsafe-inline'` | aceita para conteúdo editorial; reavaliar antes de conteúdo de usuário |
| Custo linear do `/me/enrollments` com o histórico do jogador | dentro dos limites aprovados; H1 registrada |
