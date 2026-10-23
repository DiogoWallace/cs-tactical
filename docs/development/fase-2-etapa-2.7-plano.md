# Etapa 2.7 — Revisão transversal de segurança, acessibilidade e desempenho (plano)

> Planejamento documental (branch `docs/phase-2-7-plan`, 2026-10-18), sobre a `main` em
> `5bc9f3f` (etapas 2.0–2.6 integradas, CI verde). **Nada aqui está implementado.** Cada
> subetapa só começa com aprovação própria. Estrutura aprovada em 2026-10-18; situação de cada
> decisão em [Decisões](#decisões).

## Objetivo e princípios

Transformar a revisão transversal em um processo mensurável, com escopo fechado, antes da
auditoria de fechamento (2.8). Princípios:

1. **Auditar, depois corrigir.** Cada subetapa separa três tipos de trabalho:
   - **Auditoria**: verificar e registrar evidência (não muda comportamento);
   - **Correção de defeito comprovado**: só com evidência reproduzível (teste que falha antes e
     passa depois, ou medição);
   - **Melhoria opcional**: registrada com custo e benefício; só entra com aprovação explícita.
2. **Nenhum controle de produção é enfraquecido para facilitar testes** (rate limiting, CSP,
   CSRF, cookies, proxies confiáveis, autorização).
3. **Nenhuma funcionalidade nova** (fora: Elasticsearch, Training, Teams, certificados,
   gamificação, editor de conteúdo, outros provedores de vídeo).
4. **Nenhum número inventado**: limites de desempenho saem de uma medição inicial registrada.
5. Achados seguem a escala da [auditoria da Fase 1](fase-1-auditoria.md): crítica, alta, média,
   baixa, informativa.

## Visão geral

| Subetapa | Foco | Natureza | Depende de |
|---|---|---|---|
| **2.7a** | Consistência documental e cobertura de testes | correção de documentação + testes novos | — |
| **2.7b** | Segurança | auditoria + correções comprovadas | 2.7a (documentação correta como referência); para o `academy-public`, só investigação (R1) |
| **2.7c** | Acessibilidade | auditoria + correções comprovadas | 2.7a |
| **2.7d** | Desempenho | medição + correções comprovadas | 2.7a; idealmente depois de 2.7b (correções de segurança podem mudar consultas) |

2.7b, 2.7c e 2.7d são independentes entre si em arquivos, mas compartilham a stack E2E; a ordem
aprovada (R2) é 2.7a → 2.7b → 2.7c → 2.7d, um PR por subetapa.

Condições gerais de interrupção (valem para todas): qualquer achado **crítico ou alto** que exija
mudar regra de negócio, contrato da API ou ADR aprovada para a subetapa e vai para decisão; uma
correção que derrube outro teste existente sem explicação também para.

---

## 2.7a — Consistência documental e cobertura

**Objetivo**: documentação de referência igual ao código e cenários de teste faltantes cobertos
no nível certo (unitário, integração ou E2E).

### Escopo

1. Corrigir as quatro divergências da auditoria de 2026-10-18:

   | # | Onde | Divergência | Correção prevista |
   |---|---|---|---|
   | DV1 | `docs/development/e2e.md` | "Total: 46 testes"; faltam as linhas de `learning` e `quiz` | Total real (medido na hora: hoje 57 = 53 desktop + 4 mobile, pelo `playwright test --list`) e linhas dos dois specs |
   | DV2 | `docs/architecture/academy.md` (R10), `fase-2-plano.md` (L5, D2), comentário de `academy-dashboard-card.tsx` | `last_activity_at` muda na matrícula, na conclusão de aula, **no início e no envio** de tentativa; os textos citam só parte | Texto único e completo nos três lugares (o comentário é a única mudança em código, sem efeito de comportamento) |
   | DV3 | `docs/architecture/academy.md` (limites) | `learning-write` "matrícula, abrir/concluir aula": "abrir" não existe (revisão I1) | "matrícula e conclusão de aula" |
   | DV4 | `docs/architecture/academy.md` (segurança e acessibilidade por funcionalidade) | Sem a linha do painel/navegação (2.6) | Linha da 2.6 |

2. Testes ausentes, no nível adequado:

   | Cenário | Hoje | Nível previsto | Motivo |
   |---|---|---|---|
   | Histórico de curso arquivado em "Meu progresso" | Pest (API) e Vitest (componente) | **E2E** | Atravessa importação (`--archive-missing`), API e tela; é a garantia P1/R13a vista pelo jogador |
   | Gabarito liberado depois de esgotar as tentativas | Pest (matriz R20) e **E2E** (achado na execução: o teste de acessibilidade do quiz já esgota as tentativas) | **E2E** reforçado | Esgotar é o outro caminho de liberação (Q1) e envolve o `QuizPanel`; ver [Execução](#execução-2026-10-23) |
   | Cartão do painel com API indisponível | Vitest | **Vitest** (cobertura principal, R5) | E2E só se a auditoria demonstrar uma integração não coberta pelos testes existentes; a leitura no servidor não é interceptável pelo Playwright |
   | Sessão desconhecida no cabeçalho público | Vitest | **Vitest** (mantido) | Exige o `/me` falhando com cookie válido; reproduzir no E2E exigiria derrubar a API no meio do teste e afetaria specs paralelos |
   | Sessão expirada no cartão (`anonymous`) | Vitest | **Vitest** (mantido) | O painel redireciona ao login antes (`getCurrentUser`); o estado só aparece numa corrida curta |

   Regra de escolha registrada no plano: **E2E** quando o risco está na integração entre
   importação, API, sessão e tela; **Pest** para regra de negócio, autorização e integridade;
   **Vitest** para estados de componente difíceis de provocar com a stack real.

### Fora

Reescrever documentos que não divergem; novos endpoints; mudar o conteúdo de exemplo.

### Dependências

O E2E do histórico arquivado precisa de um curso de fixture **só** para ele em
`apps/web/e2e/fixtures/academy` e de um passo que o arquive (reimportação com
`--archive-missing` dentro do spec ou fixture já arquivada). R4: fixture **exclusiva do E2E**,
com dados determinísticos e limpeza segura (a stack E2E é descartável); os cursos reais e o
banco de desenvolvimento não são tocados.

### Arquivos potencialmente afetados

`docs/development/e2e.md`, `docs/architecture/academy.md`, `docs/development/fase-2-plano.md`,
`apps/web/src/features/learning/components/academy-dashboard-card.tsx` (comentário),
`apps/web/e2e/specs/*` (novos testes), `apps/web/e2e/fixtures/academy/*`, `scripts/e2e.sh` (só
se a fixture arquivada exigir um passo extra, R4),
`apps/api/app/Modules/System/Console/PrepareE2eDatabaseCommand.php` (contas novas).

### Critérios de aceite

- As quatro divergências corrigidas; uma busca por `abrir/concluir` e por "Total: 46" não
  encontra nada.
- Os dois E2E novos (arquivado e esgotado) passam em duas execuções seguidas em stack nova, sem
  retentativas, e falham numa contraprova (ex.: com a regra R20 trocada num build local, o
  gabarito aparecer antes da hora faz o teste falhar).
- Contagens finais registradas em `e2e.md` iguais às da execução.
- Pest, concorrência, Vitest, lint, build e E2E verdes.

### Evidências

Diff dos documentos; saída do E2E com os novos testes; registro da contraprova.

### Riscos

Fixture arquivada pode interferir em outros specs que contam cursos do catálogo (o
`academy.spec` confere a lista): o curso arquivado não aparece no catálogo, mas a importação dele
precisa acontecer antes do arquivamento. Interromper se exigir mudar asserções existentes.

### Execução (2026-10-23)

| Item | Resultado |
|---|---|
| DV1–DV4 | Corrigidos em `e2e.md`, `academy.md` (R10, limites, linha da 2.6), `fase-2-plano.md` (L5, D2) e no comentário de `academy-dashboard-card.tsx`. A contagem real de E2E era 57 = **53 desktop + 4 mobile** (este plano dizia 54 + 3; corrigido) |
| Gabarito depois de esgotar | **Já tinha E2E**: o teste de acessibilidade do quiz esgota as 2 tentativas da fixture e conferia a explicação. A auditoria de 2026-10-18 errou ao apontar a lacuna. Em vez de duplicar, o teste foi reforçado: gabarito marcado, "0 tentativas restantes", nenhuma tentativa nova, e o mesmo estado depois de recarregar (vindo do servidor) |
| Histórico de curso arquivado | Novo E2E em `learning.spec.ts` com a fixture `historico-e2e` (R4): publicada na importação, concluída pela conta `history@e2e.test` via `e2e:prepare --seed-history` (Action `CompleteLesson`, sem tocar models da Academy) e arquivada por uma segunda importação de `_arquivado/`, tudo antes do Playwright no `scripts/e2e.sh` |
| Total E2E | 58 = 54 desktop + 4 mobile; **58 de 58** na stack nova (2,3 min) |
| Contraprovas | Sem a reimportação de `_arquivado` (curso continua publicado), o teste do histórico falha; com `reviewAvailable()` só por aprovação (sem o esgotamento de R20), o teste do quiz falha por não achar a explicação. Arquivos restaurados sem diff |
| Regressão | Pest 739 (738 + 1 do `--seed-history`), concorrência 5, Vitest 298, Pint, Larastan, ESLint, Prettier, TypeScript e build verdes |

---

## 2.7b — Segurança

**Objetivo**: confirmar com evidência que a Academy e o Learning mantêm as garantias da Fase 1 e
das ADRs 0015–0019, e investigar o limite `academy-public` no E2E.

Para cada item: **ameaça**, **método**, **evidência esperada** e **condição de aprovação**.

| # | Item | Ameaça | Método | Evidência | Aprovado quando |
|---|---|---|---|---|---|
| S1 | Matriz de autenticação e autorização | Rota nova sem `auth:sanctum`, sem `throttle:` ou com Policy faltando | Revisar `route:list` contra a lista pública do `RouteAuthorizationTest` e a matriz de [academy.md](../architecture/academy.md#matriz-de-autorização); conferir que cada linha da matriz tem teste (papéis × ação) | Tabela linha da matriz → teste | Toda linha tem teste nos quatro papéis; nenhuma rota fora da lista pública sem sessão |
| S2 | IDOR | Ler ou alterar matrícula, progresso ou tentativa de outro jogador | Sondagem com duas contas na stack E2E (UUID de tentativa alheia em `GET`, `submission`; slugs de outro curso); revisar uso de `whereUuid` e `scopeBindings` | Respostas 404 (sem revelar existência) | Todas 404; nenhuma diferença de tempo ou corpo que revele existência |
| S3 | Sessão e CSRF | Escrita sem token; sessão de outro usuário; cookie expirado tratado como válido | Escritas sem CSRF (esperado 419); cookie adulterado; `/me` com cookie expirado nas páginas públicas (2.6) | 419; 401 tratado como visitante; nenhum dado da conta | Todas as escritas do Learning exigem CSRF; páginas públicas com cookie inválido mostram o cabeçalho de visitante |
| S4 | Cache | Dado privado em HTML cacheado ou cache compartilhado | Conferir o shell estático do build (`.next/server/app/*.html`) por e-mail, apelido, "Sair"; `Cache-Control` das respostas autenticadas e da mídia (`no-store`) | Grep do build; cabeçalhos | Nenhum dado de sessão no shell; respostas autenticadas `no-store` |
| S5 | Limites de requisição | Automação contra escrita ou adivinhação do gabarito | Conferir cada grupo (tabela de [academy.md](../architecture/academy.md#limites-de-requisição)) contra o código e os testes de 429 | Testes existentes ou novos de 429 por grupo | Todo grupo tem teste de 429 com a chave correta (IP ou usuário) |
| S6 | CSP | XSS por conteúdo editorial; `'unsafe-inline'` aceito (ADR 0014) | Rodar o `csp.spec` incluindo as páginas da Academy, do painel e do quiz; medir violações do Report-Only; revisar as três camadas de [seguranca-http.md](../architecture/seguranca-http.md#conteúdo-editorial-da-academy-etapa-23-com-unsafe-inline) | Tabela de violações por página | CSP aplicada sem violações; Report-Only medido e registrado; nenhum `dangerouslySetInnerHTML` |
| S7 | Dependências | Vulnerabilidade conhecida em runtime | `composer audit`; `pnpm audit --prod` e `pnpm audit` | Saídas anexadas | Nenhuma alta/crítica em runtime; dev-only sem correção registrada como risco aceito (padrão do F6) |
| S8 | Integridade e resiliência | Corrida, duplicidade ou estado parcial em matrícula, conclusão e quiz | Rever os 5 testes de concorrência e as constraints; sondar envio com respostas malformadas, repetidas e fora do snapshot; conferir que falhas não deixam linhas parciais | Testes e sondagem | Nenhum 500; nenhuma linha parcial; constraints cobrem o que os testes de concorrência não cobrem |
| S9 | `academy-public` na stack E2E | Testes instáveis (429) ou, pior, "corrigir" enfraquecendo o limite ou confiando em IP escolhido pelo cliente | Investigação da [seção própria](#limite-academy-public-na-stack-e2e): reprodução controlada, cadeia de confiança, comparação | Relatório da investigação | Relatório aprovado; qualquer solução só com aprovação específica (R1), sem mudar o limite de produção nem aceitar cabeçalho de IP do cliente |

### Fora

Borda e configuração de produção (P1–P4) e G1: continuam como bloqueios de produção, sem
implementação aqui. CSP com nonce (exige desligar o PPR; decisão de arquitetura). Conteúdo gerado
por jogadores (não existe).

### Dependências

2.7a concluída. O S9 é investigação (R1); sua solução depende de aprovação específica e pode
ficar para depois da 2.7b sem bloquear os demais itens. As sondagens (S2, S3, S8) usam scripts
versionados em `scripts/`, sem credenciais, executados só em ambientes de teste autorizados e
descartáveis (stack E2E), nunca em produção (R6).

### Arquivos potencialmente afetados

Só se um defeito for comprovado: `apps/api/app/Modules/{Academy,Learning}/**`,
`apps/api/tests/**`, `apps/web/e2e/specs/csp.spec.ts`; `scripts/` (sondagens, R6). Para o S9,
nada antes da aprovação específica. Documentação: este plano e `seguranca-http.md`
(`fase-1-auditoria.md` não muda; a auditoria da Fase 2 nasce na 2.8).

### Critérios de aceite

- S1–S9 com evidência registrada e situação (aprovado, corrigido, risco aceito com justificativa).
- Todo defeito corrigido tem teste que falhava antes e passa depois.
- Nenhuma mudança em `academy-public`, `api`, `learning-write`, `quiz-attempt`,
  `TRUSTED_PROXIES` de produção, CSP aplicada ou CSRF.
- Suíte completa verde; E2E sem retentativas.

### Riscos e interrupção

Achado que exija mudar ADR (ex.: política de 404 vs 403) para e vai para decisão.

---

## Limite `academy-public` na stack E2E

### 1. Como o limite é identificado

`AcademyServiceProvider`: `RateLimiter::for('academy-public', fn ($r) => Limit::perMinute(120)->by($r->ip()))`,
nas três rotas de leitura do catálogo (`throttle:academy-public` em `Academy/routes.php`). A
chave é **só o IP**, mesmo com sessão (as rotas são públicas). `$request->ip()` usa o
`X-Forwarded-For` **apenas** quando a conexão vem de um proxy confiável (`TRUSTED_PROXIES`,
ADR 0009): no E2E, só o container `web` (`172.31.251.10`).

### 2. Por que os testes compartilham o mesmo identificador

As páginas da Academy leem a API **no servidor do Next** (`serverFetch`). O `serverFetch` repassa
o `X-Forwarded-For` que recebeu; na stack E2E não há borda, e o Playwright não envia esse
cabeçalho. Resultado: toda leitura chega à API vinda do `web`, sem `X-Forwarded-For`, e
`$request->ip()` é o IP do próprio `web`. **Todos os testes, de todos os workers, gastam o mesmo
contador de 120/min.** Em produção não acontece: a borda substitui o `X-Forwarded-For` pelo IP
real e o Next o repassa (P1).

### 3. Cenários que provocam 429

Medição em 2026-10-18 (E2E completo em stack nova, `main` em `5bc9f3f`, 3 workers, 57 testes
aprovados, log de acesso da API):

| Métrica | Valor |
|---|---|
| Requisições às três rotas do `academy-public` | **106** (catálogo 23, curso 50, aula 33); 97 × 200 e 9 × 404 (testes de conteúdo indisponível) |
| IP visto pela API | **um só**: `172.31.251.10` (o container `web`) em todas |
| Distribuição por minuto | 42, 39 e 25 |
| Pico em qualquer janela de 60 s | **56** (47% do limite de 120) |
| Respostas 429 | **0** |
| Outras leituras do mesmo período (outros limites) | `/me` 178 (99 × 200, 79 × 401), `/progress` 42, mídia 45 |

Conclusões:

- Uma execução normal fica abaixo do limite, com folga de ~2×. O 429 aparece quando o volume de
  leituras da Academy num minuto passa de 120 **somando todos os testes e workers**: repetição de
  specs (`--repeat-each`, observado nas etapas 2.4/2.5), mais workers, specs novos da Academy
  (2.7a acrescenta dois) ou depuração com várias execuções seguidas na mesma stack (`E2E_KEEP=1`).
- O risco cresce com a suíte: cada spec novo da Academy consome do mesmo contador. Não é um
  problema de produção; é de isolamento dos testes.
- Os 79 `/me` com 401 são visitantes com cookie de sessão (o Laravel emite cookie também para
  visitantes): é a leitura extra da 2.6 nas páginas públicas e das telas de login. Entra na 2.7d.

### 4. Alternativas de isolamento

Situação da decisão R1: **investigação aprovada; solução técnica pendente de decisão.** Nenhuma
alternativa abaixo está aprovada. A tabela é o ponto de partida da comparação (passo 3 da
investigação), não uma escolha.

| # | Alternativa | Como | Consequência de segurança | Avaliação preliminar |
|---|---|---|---|---|
| A | Playwright envia um `X-Forwarded-For` próprio por teste, simulando a borda | `extraHTTPHeaders` com um IP distinto por teste; o Next repassa; a API aceita porque vem do `web` confiável | Não muda produção, mas o isolamento passa a **depender de um cabeçalho de IP escolhido pelo cliente** e aceito pela cadeia do E2E: é o mesmo comportamento do F1/P1, que deveria ser eliminado, não aproveitado | **Não aprovada** (decisão do responsável, 2026-10-18); contraria as regras obrigatórias (passo 4 da investigação) |
| B | Limite configurável por ambiente (ex.: valor alto só no E2E) | Variável de ambiente lida no provider | Produção continua 120/min se a variável não existir, mas cria um ajuste que pode ser mal configurado em produção; o E2E deixa de exercitar o limite real | A comparar; risco de configuração |
| C | Desligar o `throttle` com `APP_ENV=e2e` | Condição no provider ou middleware | Desvio por ambiente no código de produção; o E2E deixa de testar o controle | Contraria a regra 4 |
| D | Limpar o contador do Redis entre testes | Remover a chave do limitador antes de cada teste | Não toca produção, mas corre contra os workers em paralelo e esconde o consumo real | Fraca |
| E | Serializar os specs da Academy ou reduzir chamadas por teste | Menos workers nesses specs, menos navegações | Nenhuma; o contador continua compartilhado e o problema volta com mais testes | Paliativo |
| F | Borda (Nginx) na stack E2E, como a de referência | Container de borda na frente do `web` | Mais fiel à produção; a borda **substitui** o cabeçalho pelo IP da conexão. Sozinha, todos os testes vêm do mesmo container → mesmo contador; com clientes em origens de rede distintas (ex.: um container ou endereço por worker), o IP passa a ser o da conexão, sem cabeçalho escolhido pelo cliente | A investigar (custo de infraestrutura de teste) |
| G | Chave do limitador que não dependa só do IP **quando a origem é o próprio servidor do Next**, com identificação autenticada entre `web` e API | Ex.: segredo compartilhado do Next para a API em leituras do servidor | Mudança de arquitetura e de código de produção; precisa de ADR | A investigar só se A–F falharem |

### 5. Investigação (aprovada) antes de qualquer mudança

1. **Reprodução controlada.** Provocar o 429 do `academy-public` na stack E2E de forma
   determinística (ex.: um spec ou script que faça N leituras do catálogo num minuto, e a
   repetição de specs da Academy), registrando o número de requisições, a janela e o primeiro 429.
   Sem reprodução, nenhuma mudança de comportamento.
2. **Cadeia real de confiança e identificação do cliente.** Documentar, com evidência (logs da
   API, cabeçalhos recebidos em cada salto), o caminho navegador → Next (página) → API e
   navegador → Next (rewrite `/api`) → API na stack E2E: quem define `X-Forwarded-For`, quem o
   repassa, o que `TRUSTED_PROXIES` aceita e qual `$request->ip()` resulta em cada caso. Comparar
   com a topologia de produção prevista (borda de referência, P1–P3).
3. **Comparar soluções** (tabela acima e outras que surgirem) pelos critérios: não enfraquece
   produção; não depende de cabeçalho de IP fornecido pelo cliente; continua testando o limite
   real; custo de manutenção; fidelidade à produção.
4. **Regras obrigatórias para qualquer solução**:
   - nenhuma mudança no limite de produção, em `TRUSTED_PROXIES` de produção, na borda de
     referência nem nos controles de rate limiting para facilitar testes;
   - **proibido confiar em cabeçalhos de IP arbitrários fornecidos pelo cliente** como mecanismo
     de isolamento;
   - um teste de controle continua provando o 429 com a mesma origem.
5. **Aprovação específica** do responsável antes de implementar qualquer solução, com base no
   relatório da investigação (reprodução, cadeia de confiança, comparação e recomendação).

### 6. Entregável da investigação

Relatório (seção deste plano ou documento próprio) com a reprodução, o mapa da cadeia de
confiança, a tabela comparativa preenchida e uma recomendação. A implementação, se aprovada,
vira item próprio da 2.7b com testes e contraprova. Até lá, a mitigação é operacional: rodar a
Academy uma vez por stack nova, sem `--repeat-each`.

---

## 2.7c — Acessibilidade

**Objetivo**: além do axe automático (que já roda nos dois temas), verificar o que ele não
pega: teclado, ordem de foco, leitores de tela, contraste real, celular e movimento reduzido.

### Páginas e componentes

| Área | Páginas | Componentes |
|---|---|---|
| Público | `/`, `/status`, `/academy` (com filtro e paginação), curso, aula (com vídeo e quiz) | `SessionHeader`/`SiteHeader`, catálogo, `LevelFilter`, `CourseCard`, `LessonView` (trilha, lista lateral recolhível, anterior/próxima), blocos (Markdown, callout, imagem, vídeo, `quiz_ref`) |
| Autenticação | login, cadastro, esqueci/redefinir senha, verificação de e-mail | formulários (Fase 1, regressão) |
| Área logada | `/painel`, `/painel/academy`, `/painel/conta`, 404 | `AppNav` (menu do celular), `AcademyDashboardCard`, `MyProgress`, `EnrollmentPanel`, `CompleteLessonCard`, `QuizPanel` (visão geral, formulário, resultado, revisão) |

### Verificações

| # | Item | Método | Aprovado quando |
|---|---|---|---|
| X1 | Só teclado | Percorrer os fluxos completos (catálogo → aula → concluir → quiz → resultado → Meu progresso → painel → sair) sem mouse; E2E de teclado onde ainda não existe | Todos os fluxos concluídos só com teclado; nenhuma armadilha de foco |
| X2 | Ordem e foco visível | Inspeção + E2E (`toBeFocused`, contorno visível); foco depois de ações (matricular, concluir, enviar quiz, abrir/fechar menu) | Ordem segue a leitura; foco nunca some nem vai para `body` depois de uma ação |
| X3 | Semântica e leitor de tela | Revisão de marcos (`banner`, `nav` com nomes distintos, `main`), títulos, `aria-live` dos anúncios (conclusão, resultado do quiz), `progress` com `aria-valuetext`; verificação manual com **NVDA** (R7), registrando navegador e versões, cenários, resultados e limitações, em ao menos os fluxos de catálogo, aula, quiz, painel e menu do celular | Nenhum anúncio duplicado ou ausente; nomes acessíveis únicos por página (ex.: dois `nav` "Principal" só quando um está escondido); todo problema do NVDA avaliado e tratado ou registrado como risco aceito |
| X4 | Contraste e temas | axe `color-contrast` já roda; complementar com conferência de estados não cobertos (foco, hover, desabilitado, `progress`, `Badge`) nos dois temas | Todos os pares de texto ≥ 4,5:1 (≥ 3:1 para texto grande e componentes de interface) |
| X5 | Celular | Pixel 7 e uma largura de 320 px: rolagem horizontal, alvos de toque, menu lateral, quiz | Sem rolagem horizontal em 320 px; alvos ≥ 24×24 px (WCAG 2.2 2.5.8 como meta) |
| X6 | Movimento reduzido | `prefers-reduced-motion: reduce` emulado no Playwright; inventário de `animate-*`/`transition` (hoje só `lesson-view`, `video-block` e `course-card` usam `motion-reduce`; `animate-spin`/`animate-pulse` de esqueletos e botões não) | Nenhuma animação não essencial com a preferência ativa (achado a confirmar; não é defeito comprovado ainda) |
| X7 | Estados | Carregando, vazio, erro, sucesso em cada tela da tabela: `role="status"`/`aria-busy`, `role="alert"`, mensagens com saída | Todo estado tem papel e texto; nenhum esqueleto sem nome acessível |

### Fora

Redesenho visual; novos componentes; tradução.

### Arquivos potencialmente afetados

Só com defeito comprovado: componentes em `apps/web/src/features/**` e `components/ui/**`,
`app/globals.css` (movimento reduzido), specs E2E de acessibilidade.

### Critérios de aceite

X1–X7 com evidência (tabela página × item) e situação; axe sem violações sérias/críticas nos dois
temas em todas as páginas da tabela (hoje faltam a home logada e `/status` logado); cada correção
com teste (Vitest ou E2E) que falhava antes.

### Riscos

Verificação com leitor de tela é manual e depende do ambiente; registrar versão do leitor e do
navegador. A ausência de execução automatizada com NVDA não bloqueia a CI por si só (R7); os
problemas encontrados, sim, precisam ser avaliados e tratados. Interromper se uma correção exigir
mudar o design system.

---

## 2.7d — Desempenho

**Objetivo**: uma linha de base reproduzível e limites de regressão derivados dela; corrigir só o
que a medição comprovar.

### Plano de medição

1. **Ambiente**: stack E2E (build de produção do Next, Nginx + PHP-FPM da API, Postgres e Redis
   em `tmpfs`), máquina e carga registradas (`nproc`, memória, `uptime` antes e depois). Nunca no
   banco de desenvolvimento.
2. **Cenários de dados**:
   - **pequeno**: o conteúdo de E2E atual (`apps/web/e2e/fixtures/academy`: dois cursos
     publicados e um em rascunho);
   - **ampliado** (R3, aprovado como cenário inicial): **50 cursos × 5 módulos × 8 aulas = 2.000
     aulas**, com um jogador matriculado em parte deles, com conclusões e tentativas. Gerado
     **exclusivamente em ambiente de testes descartável** (stack E2E ou banco de teste próprio),
     por um gerador de fixtures fora do conteúdo real; nunca no banco de desenvolvimento.
3. **O que medir**:
   - **API**: tempo de resposta (p50/p95, N repetições após aquecimento) e **número de consultas**
     por endpoint: catálogo, curso, aula, `/progress`, `/me/enrollments`, `/me/quiz-attempts`,
     início e envio de tentativa. As contagens fixas já testadas (3, 4, 6; `/progress` 5;
     `/me/enrollments` 4) são verificadas também no cenário ampliado;
   - **Banco**: `EXPLAIN (ANALYZE, BUFFERS)` das consultas de progresso e das listagens no cenário
     ampliado; uso dos índices de [academy-learning.md](../database/academy-learning.md);
   - **Next**: TTFB e tempo até o conteúdo final (streaming) de `/academy`, curso, aula e
     `/painel`, com e sem sessão; **chamadas à API por página** (pelo log de acesso), para medir o
     `/me` extra das páginas públicas (2.6). Referência já medida no E2E completo de 2026-10-18:
     178 `/me` (79 com 401, de visitantes com cookie) para 106 leituras do catálogo;
   - **E2E**: duração por teste na CI (referência atual: testes de acessibilidade 7–15 s; limite de
     30 s) para detectar regressão de tempo.
4. **Ferramentas**: as já existentes (log de acesso da API, `DB::listen`/contagem nos testes,
   `EXPLAIN`, Playwright para tempos de navegação). Ferramenta nova só com aprovação.

### Limites de regressão

Definidos **depois** das medições de referência, considerando a variabilidade do ambiente
(repetições, dispersão e carga da máquina registradas). Hipótese inicial, **não** limite
definitivo: p95 do cenário ampliado + 20% por endpoint, e número de consultas igual ao medido.
Os critérios finais são apresentados para aprovação junto com a medição (R3).

### Separação

| Tipo | Exemplo | Tratamento |
|---|---|---|
| Comprovado | consulta cresce com o número de aulas (N+1) no cenário ampliado | correção com teste de contagem |
| Hipotético | cache do catálogo, materializar o percentual | registrar; só com medição que justifique e aprovação |

### Fora

Cache de dados entre usuários; CDN; mudanças de infraestrutura; Elasticsearch.

### Arquivos potencialmente afetados

Só com problema comprovado: `apps/api/app/Modules/{Academy,Learning}/Queries/**`, migrations de
índice (exigiria aprovação e procedimento de dev), `apps/web/src/features/**/api/*`.

### Critérios de aceite

Linha de base registrada (este documento ou `docs/development/desempenho.md`) com ambiente,
dados e comandos para repetir; contagem de consultas constante entre cenário pequeno e ampliado
em todos os endpoints; critérios finais de regressão apresentados com a variabilidade medida e
aprovados; cada correção com medição antes/depois; nenhum dado gerado fora do ambiente
descartável.

### Riscos

Medição em máquina compartilhada varia (visto em 2026-10-18: load average 6–10 duplicou o tempo
local dos E2E); registrar a carga e repetir. Interromper se uma correção exigir migration.

---

## Matriz de riscos e prioridades

| # | Risco | Prob. | Impacto | Prioridade | Onde |
|---|---|---|---|---|---|
| K1 | E2E instável por 429 do `academy-public` compartilhado | média | médio (falsos negativos, retrabalho) | **alta** | 2.7b S9 |
| K2 | Documentação divergente leva a decisão errada na 2.8 | alta (já existe) | baixo | **alta** (barata) | 2.7a |
| K3 | XSS executável se as três camadas do conteúdo falharem (`'unsafe-inline'`) | baixa | alto | média | 2.7b S6 |
| K4 | Dependência vulnerável em runtime | desconhecida | alto | média | 2.7b S7 |
| K5 | Desempenho degrada com catálogo maior (percentual calculado na leitura) | desconhecida | médio | média | 2.7d |
| K6 | Movimento reduzido não respeitado em esqueletos e botões | média (a confirmar) | baixo | baixa | 2.7c X6 |
| K7 | Testes E2E perto do limite de 30 s em máquina carregada | média (local) | baixo | baixa | 2.7d |
| K8 | P1–P4 (borda, proxies, configuração) | — | alto em produção | bloqueio de produção, fora da 2.7 | 2.8 / deploy |
| K9 | G1 (`main` sem proteção) | — | médio | bloqueio de produção, fora da 2.7 | decisão do responsável |

## Estratégia de testes

- **Regressão sempre**: Pest, concorrência (`make test-concurrency`), Pint, Larastan, Vitest,
  ESLint, Prettier, TypeScript, build, E2E completo em stack nova, sem retentativas.
- **Defeito**: teste que falha antes e passa depois (registrado no PR).
- **Contraprova** para testes novos que protegem regras (como nos testes de concorrência e na
  hidratação da 2.6).
- **Sondagens** (2.7b) na stack E2E, com resultados em tabela, como na etapa 1.8.
- **Medições** (2.7d) com ambiente e carga registrados.

## Dependências para iniciar a 2.8

A 2.8 (auditoria e fechamento) só começa quando:

1. 2.7a–2.7d estiverem integradas na `main` com CI verde, ou cada item não feito estiver
   registrado como risco aceito com justificativa aprovada;
2. todo achado crítico ou alto da 2.7 estiver corrigido ou formalmente aceito;
3. a linha de base de desempenho e os limites de regressão estiverem aprovados;
4. a investigação do `academy-public` (R1) estiver concluída e a solução estiver aprovada e
   implementada, ou explicitamente adiada com a mitigação operacional registrada.

A 2.8 produz, no padrão da Fase 1: `docs/development/fase-2-auditoria.md` (conclusão para
desenvolvimento e para produção, achados com severidade e evidência, sondagens, dependências,
testes executados, documentos e ADRs) e `docs/development/fase-2-relatorio.md` (por etapa:
arquivos, decisões, testes, pendências). **P1–P4 e G1 continuam em
[bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md)** até cada um ser resolvido e
verificado; a 2.8 só atualiza a situação deles, sem removê-los.

## Decisões

Registradas em 2026-10-18 pelo responsável. "Aprovada" vale para o planejamento; a execução de
cada subetapa continua exigindo autorização própria.

| # | Decisão | Situação | Detalhe |
|---|---|---|---|
| R1 | Isolamento do `academy-public` no E2E | **Investigação aprovada; solução pendente de decisão** | Alternativa A (`X-Forwarded-For` enviado pelo Playwright) não aprovada. Antes de qualquer mudança: reprodução controlada, cadeia de confiança dos proxies, comparação sem enfraquecer a segurança, proibição de confiar em cabeçalho de IP do cliente e aprovação específica ([seção própria](#limite-academy-public-na-stack-e2e)) |
| R2 | Organização | Aprovada | Um PR por subetapa, na sequência 2.7a → 2.7b → 2.7c → 2.7d |
| R3 | Cenário de carga | Aprovada (cenário inicial) | 50 cursos × 5 módulos × 8 aulas = 2.000 aulas, só em ambiente de testes descartável. Margem de 20% é hipótese inicial; critérios finais depois das medições de referência, com a variabilidade do ambiente |
| R4 | Fixture arquivada | Aprovada | Exclusiva do E2E, dados determinísticos, limpeza segura; cursos reais e banco de desenvolvimento intocados |
| R5 | API indisponível no cartão | Aprovada | Cobertura principal no Vitest; E2E só se a auditoria demonstrar integração não coberta |
| R6 | Sondagens de segurança | Aprovada (planejamento) | Scripts versionados em `scripts/`, sem credenciais; execução só em ambientes de teste autorizados e descartáveis; nenhuma alteração em produção |
| R7 | Leitor de tela | Aprovada | NVDA na auditoria manual da 2.7c, com navegador, versões, cenários, resultados e limitações registrados; não bloqueia a CI por si só; problemas avaliados e tratados |
