# Plano da Fase 2 — Academy

Plano aprovado em 2026-10-17 (etapas 2.0 a 2.8, módulos `Academy` e `Learning`). Este documento é
o resultado da **etapa 2.0** (desenho, sem código), com as decisões P1–P8 aprovadas.

## Documentos da etapa 2.0

| Documento | Conteúdo |
|---|---|
| [ADR 0015](../decisions/0015-academy-e-learning-publicacao-e-visibilidade.md) | Módulos, estados de publicação, visibilidade efetiva (todos os ancestrais publicados), arquivar em vez de apagar |
| [ADR 0016](../decisions/0016-schemas-de-blocos-e-importacao-de-conteudo.md) | Schemas versionados dos blocos; importação por `key`, em três fases, idempotente, sem exclusões implícitas |
| [ADR 0017](../decisions/0017-progresso-diante-de-mudancas-no-conteudo.md) | Progresso diante de edição, reorganização, inclusão e arquivamento |
| [ADR 0018](../decisions/0018-quizzes-tentativas-e-historico.md) | Correção no servidor, tentativas, snapshot, histórico e liberação do gabarito |
| [ADR 0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md) | Vídeos de provedores autorizados, CSP por rota, Markdown sem HTML, imagens por hash e órfãos |
| [academy-learning.md](../database/academy-learning.md) | Modelo de dados detalhado |
| [academy.md](../architecture/academy.md) | Regras de negócio, matriz de autorização, limites, segurança e acessibilidade por funcionalidade |
| [academy-v1.md](../api/academy-v1.md) | Contratos iniciais da API |
| [conteudo-importavel.md](../academy/conteudo-importavel.md) | Estrutura dos arquivos de conteúdo e schemas dos blocos |

## Decisões consolidadas

### Produto

- Catálogo e aulas publicadas são públicos; matrícula, progresso e quizzes exigem login.
- Rascunhos e arquivados só para quem tem `manage-academy-content`.
- Vídeos só do YouTube (`youtube-nocookie.com`), com validação de URL e CSP só nas aulas.
- Imagens pelo Storage do Laravel (disco local; S3 configurável depois).
- Lineups, mapas e estratégias fora da Fase 2.
- Markdown sem HTML bruto nem scripts.
- Quizzes: 3 tentativas e 70% por padrão, ajustáveis por quiz; reprovar não impede continuar.

### P1–P8

| # | Decisão |
|---|---|
| P1 | **Aula arquivada não é lida** por ninguém sem `manage-academy-content`, nem por quem estava matriculado. Conclusões, tentativas, notas e cursos concluídos continuam no histórico; conclusões de curso já conquistadas não são invalidadas. |
| P2 | Mudar a **revisão** de um quiz não reinicia o limite de tentativas. Mudança substancial na avaliação = **novo quiz** (outra `key`). Snapshots e resultados anteriores preservados. |
| P3 | Estudar não exige e-mail confirmado. |
| P4 | Sem cancelar matrícula no MVP. |
| P5 | Conteúdo em `content/academy` neste repositório; a CI valida com `--dry-run`. |
| P6 | Múltipla escolha: tudo ou nada por pergunta. |
| P7 | Antes da liberação: nota e acerto/erro por pergunta, **sem** gabarito nem explicações. Gabarito e explicações só depois de **aprovar ou esgotar** as tentativas. Regra aplicada pelo backend em todas as respostas. |
| P8 | Só YouTube na Fase 2. |

### Ajuste técnico: imagens × transação

O PostgreSQL não desfaz arquivos. Estratégia (ADR 0019, itens 11–16): imagens gravadas **antes**
da transação, com nome igual ao hash do conteúdo (imutáveis, deduplicadas, escrita atômica com
conferência do hash); a transação registra a mídia em `academy_media` e só referencia arquivos
existentes e conferidos — **sem referência quebrada nem publicação parcial**. Falha depois dos
arquivos deixa órfãos inofensivos. A importação nunca sobrescreve nem apaga mídia registrada.

`academy:media-prune` (ADR 0019, item 14): usa o **mesmo *advisory lock* da importação** (nunca
roda em paralelo com ela), só considera arquivos com mais de **24 horas** e, **para cada arquivo,
logo antes de remover**, confere de novo que não há referência (registro em `academy_media`, capa
de curso ou bloco `image`). Havendo referência, o arquivo fica e é relatado como anomalia.

### Decisão derivada da P7 (confirmada)

- **Depois de aprovado, não há tentativa nova** daquele quiz: 409 `quiz_already_passed`.
- A aprovação é **preservada** mesmo que a revisão do quiz mude; o limite de tentativas não é
  reiniciado.
- Novas tentativas só num **outro quiz**, com `key` própria.
- A divulgação do gabarito segue as regras da ADR 0018 (itens 11–14).

## Revisão de coerência (inconsistências encontradas e corrigidas)

| # | Onde | Problema | Correção |
|---|---|---|---|
| I1 | regras × API | R10 gravava `in_progress` ao **ler** a aula, mas a leitura é pública e cacheável e não havia endpoint para isso (leitura com efeito colateral). | Ler não grava nada; progresso só por ação explícita (concluir aula ou iniciar o quiz dela). |
| I2 | conteúdo × modelo | Pergunta "removida se nunca respondida" quebraria uma tentativa **aberta**, que responde ao seu snapshot (FK RESTRICT na resposta). | Perguntas e opções só são removidas se o quiz **nunca** teve tentativa; senão, aposentadas. ADR 0016 e 0018 alinhadas. |
| I3 | modelo × importação | `courses.cover_image_path` e o bloco `image` com caminho livre não garantiam que o arquivo existia nem permitiam limpar órfãos. | Tabela `academy_media` (hash, caminho, tipo, dimensões); capa por `cover_media_id` (FK) e bloco `image` pelo `sha256`, validado na transação. |
| I4 | API | O destino do histórico de conteúdo arquivado e o acesso a `/progress` de curso arquivado estavam indefinidos. | `/progress` de curso não público → 404; histórico em `/me/enrollments` e `/me/quiz-attempts` (R13a). |
| I5 | API × P7 | Faltava onde o jogador consulta uma tentativa enviada e o formato com/sem gabarito. | Novo `GET /api/v1/academy/quiz-attempts/{attempt}` e "formato comum" do resultado, com `review_available` calculado no servidor. |
| I6 | ADR 0018 × P7 | O snapshot não guardava as explicações, necessárias para a liberação posterior. | Snapshot inclui gabarito **e** explicações; só saem da API com revisão liberada. |
| I7 | ADR 0016 | "Sem exclusões implícitas" parecia conflitar com a remoção de perguntas. | Escopo explicitado: vale para curso, módulo, aula e quiz; perguntas e opções seguem a ADR 0018. |

Verificações pedidas:

- **Visibilidade**: a regra exige curso, módulo **e** aula publicados (R2/ADR 0015); quiz segue a
  aula, então herda os três; aplicada em leitura, conclusão, início e envio de tentativa.
- **Gabarito**: não sai em `quiz_ref` da aula, no início da tentativa, em `/progress`, no
  envio nem no histórico antes da liberação; o snapshot bruto nunca é serializado (um único
  componente monta as respostas sobre tentativas, com testes em matriz).

## Etapa 2.1 — Catálogo no banco e importador (plano final)

**Objetivo**: o módulo `Academy` existe no banco e o conteúdo dos arquivos chega até ele de forma
validada e segura, sem nenhuma rota HTTP nem tela.

Entregas:

1. **Módulo `Academy`** (`app/Modules/Academy`) com provider próprio e testes de arquitetura de
   fronteira (nenhum outro módulo usa os models dele; `Learning` passa a usar a partir da 2.4).
2. **Migrations** de `academy_media`, `courses`, `course_modules`, `lessons`, `quizzes`,
   `quiz_questions` e `quiz_options` conforme [academy-learning.md](../database/academy-learning.md):
   checks, únicos adiáveis, únicos parciais, FKs compostas e RESTRICT.
3. **Models, enums (`PublicationStatus`, `CourseLevel`, `QuestionKind`) e factories**; escopo
   `visible()` com a visibilidade efetiva (R2).
4. **Schemas dos blocos v1** em PHP (uma classe por tipo, validação estrita) e fixtures
   compartilhadas em `content/academy/_fixtures` para o espelho Zod da 2.3.
5. **Disco `academy`** em `config/filesystems.php` (local), fora do diretório público por padrão.
6. **Importador `academy:import`**:
   - fase (a): leitura dos YAML e validação completa, com erros por arquivo e caminho do campo;
   - fase (b): imagens validadas pelo conteúdo, gravadas por hash com escrita atômica e
     conferência; arquivo existente é reaproveitado;
   - fase (c): uma transação com *upsert* por `key`, registro em `academy_media`, revisão do quiz,
     aposentadoria de perguntas/opções (ou remoção se o quiz nunca teve tentativa);
   - idempotência (nada é escrito quando não há mudança), `--dry-run`, `--archive-missing` com
     confirmação, relatório de saída, *advisory lock*.
7. **`academy:media-prune`**: obtém o *advisory lock* da importação; considera arquivos do disco
   `academy` com mais de 24 h; reconfere, para cada um e logo antes de remover, a ausência de
   referência (registro, capa, bloco `image`); remove um por vez com log; `--dry-run`; nunca
   remove arquivo registrado ou referenciado.
8. **Conteúdo de exemplo** em `content/academy/` (um curso, dois módulos, três aulas, um quiz,
   duas imagens).
9. **Documentação**: README de `content/academy`, modelo de dados atualizado com o implementado,
   relatório da fase.

Testes (Pest), escritos junto com cada entrega:

- **Banco**: cada check, único (inclusive adiável e parcial) e FK com inserção inválida; FK
  composta impede aula em módulo de outro curso.
- **Visibilidade**: matriz 3×3×3 de estados (curso × módulo × aula).
- **Importador**: criação; segunda importação sem nenhuma escrita; erro no último arquivo não grava
  nada; renomear slug mantém o registro; item ausente intocado; `--archive-missing`; `--dry-run`
  sem escrita; chave duplicada recusada; aula movida para outro curso recusada; importação
  simultânea bloqueada pelo lock.
- **Imagens**: tipo conferido pelo conteúdo (extensão falsa e SVG recusados); limite de tamanho e
  dimensões; mesmo arquivo em duas aulas gera um único registro; **falha simulada na transação
  não deixa linha em `academy_media` nem referência**, e o arquivo órfão é removido pelo
  `media-prune` (respeitando as 24 h); arquivo registrado nunca é removido; arquivo sem registro
  mas citado por um bloco ou capa **não** é removido (anomalia relatada); `media-prune` não roda
  enquanto uma importação segura o lock (e vice-versa).
- **Blocos**: um teste por regra de cada tipo (Markdown com HTML, imagem Markdown, link
  `javascript:`, vídeo de outro domínio, `alt` ausente, campo extra, tipo desconhecido, limites).
- **Quizzes**: gabarito inválido recusado (escolha única com duas corretas, nenhuma correta);
  pergunta retirada de quiz com tentativa é aposentada (tentativa criada direto no banco); revisão
  sobe só com mudança relevante.

Fora da 2.1: rotas HTTP (2.2), telas (2.3), `Learning` (2.4), tentativas pela API (2.5).

**Critérios de aceite**: `make test`, `make lint`, build e `make e2e` verdes; importação do
conteúdo de exemplo idempotente; nenhum endpoint novo (o teste de rotas confirma); nenhum arquivo
órfão depois da suíte.

### Etapa 2.1 — ajustes feitos na implementação

Diferenças em relação ao desenho da 2.0, já refletidas nos documentos de referência:

| # | Desenho da 2.0 | Implementado | Motivo |
|---|---|---|---|
| A1 | `quizzes.lesson_id` único | `retired_at` em `quizzes` + único **parcial** `(lesson_id) WHERE retired_at IS NULL` | P2: uma aula pode receber um quiz novo enquanto o anterior, com tentativas, continua respondendo pelos snapshots |
| A2 | Slug único simples | Slug único **adiável** (cursos, módulos, aulas) | Trocar slugs entre dois itens numa mesma importação |
| A3 | Caminho `academy/{sha[0:2]}/{sha}.{ext}` | `media/{sha[0:2]}/{sha}.{ext}` no disco `academy` (`storage/app/academy`) | O nome do disco já diz "academy"; temporários ficam em `tmp/` no mesmo disco (rename atômico) |
| A4 | — | `content/` montado só leitura no container `api` (`/var/www/content`) e `ACADEMY_CONTENT_PATH` no `compose.yaml` | O container só monta `apps/api`; na CI (sem container) vale o padrão `base_path('../../content/academy')` |
| A5 | — | Dependência `symfony/yaml` | Leitura estrita do YAML (chave repetida, tags e objetos PHP reprovam) |
| A6 | — | Contrato `QuizUsage` (Academy), implementação nula até a 2.4 | A Academy não conhece o Learning; o teste de aposentadoria usa um `QuizUsage` falso no lugar de uma tentativa no banco, que só existe na 2.4 |
| A7 | P5: CI valida com `--dry-run` | Um teste Pest importa `content/academy` (simulação e importação real) em toda execução da suíte | Mesmo efeito, sem passo extra na CI |
| A8 | — | Arquivos `.md` e entradas com `_`/`.` ignorados; links simbólicos reprovam; `_fixtures/blocks-v1.json` com os casos dos blocos | Anotações e rascunhos ao lado do conteúdo; casos compartilhados com o Zod da 2.3 |

Limpeza de arquivos (ADR 0019): **capa citada sem registro** não pode acontecer (FK de
`cover_media_id`), então o `media-prune` reconfere registro e blocos `image`; aula que cite um
hash sem registro é anomalia (arquivo mantido, comando termina com erro).

## Etapa 2.2 — API do catálogo (implementada)

Entregas: `GET /api/v1/academy/courses`, `/courses/{course}`, `/courses/{course}/lessons/{lesson}`
e `/media/{sha256}.{ext}` ([academy-v1.md](../api/academy-v1.md)), na lista pública do
`RouteAuthorizationTest`, com `throttle:academy-public` / `throttle:academy-media`.

Estrutura no módulo `Academy`:

| Arquivo | Papel |
|---|---|
| `routes.php` | rotas e formato dos slugs e do nome do arquivo (404 no roteador) |
| `Queries/CatalogQuery.php`, `Queries/LessonPage.php` | único ponto de leitura dos controllers; `visible()` em tudo fora do modo editorial; carregamento antecipado (3, 4 e 6 consultas) |
| `Http/EditorialAccess.php` | `include_unpublished` + Gate `manage-academy-content` pelo usuário da sessão (Sanctum), calculado uma vez por requisição |
| `Http/Controllers/*` | sem acesso a models nem ao banco (teste de arquitetura) |
| `Http/Resources/*` | lista fechada de campos; resolução dos blocos (`image` → URL, `quiz_ref` → resumo) |
| `Http/MediaResponse.php` | resposta da imagem: tipo do registro, conferência do arquivo, `no-store` |

### Etapa 2.2 — ajustes feitos na implementação

| # | Desenho da 2.0 | Implementado | Motivo |
|---|---|---|---|
| B1 | URL de imagem `/media/academy/{sha[0:2]}/{sha}.{ext}` (exemplo do contrato) | `/api/v1/academy/media/{sha256}.{ext}` | O proxy do Next e a borda de referência só encaminham `/api` e `/sanctum` à API; a rota nova não muda frontend nem borda e entra no teste de rotas (decisão do responsável, 2026-10-17) |
| B2 | Acesso à mídia não definido | Só imagem referenciada por conteúdo público (capa de curso público ou bloco `image` de aula pública); editores recebem as demais registradas. Nenhuma imagem vai para cache (`no-store`, sem `ETag` nem 304) | Não expor imagem de rascunho ou de conteúdo arquivado a quem conheça o hash, nem deixar um cache reaproveitar uma autorização anterior depois de arquivar ou despublicar (decisões do responsável; o `no-store` veio na revisão da 2.2) |
| B3 | Imagens no limite `academy-public` | Limite próprio `academy-media`, 600/min por IP | Uma página do catálogo ou de aula carrega várias imagens (decisão do responsável) |
| B4 | `include_unpublished` só descrito na listagem | Vale nos três endpoints; no modo editorial curso, módulos e aulas ganham `status`, as contagens incluem tudo e `previous`/`next` consideram todas as aulas | Matriz de autorização (ler rascunho e arquivado com `include_unpublished`) |
| B5 | `page ≥ 1` | `level` e `page` (1..10 000) validados: **422** | Evita OFFSET gigante e erro do banco com número fora do inteiro |
| B6 | `links`/`meta` padrão do Laravel | `links` relativos à origem; `meta` com `current_page`, `last_page`, `per_page`, `total` | Atrás do proxy do Next o Host é o endereço interno da API (`api:8080`), que não deve sair nas respostas |
| B7 | Teste "nenhuma rota HTTP da Academy" (critério da 2.1) | Substituído pela lista exata das quatro rotas de leitura | A 2.2 cria as rotas; a importação continua só pelo terminal |

Testes: `tests/Feature/Academy/CatalogApiTest.php` (listagem, filtros, paginação, detalhe,
aula, matriz 3×3×3 pelo HTTP, papéis × `include_unpublished`, sessão real do frontend, ausência
de gabarito e de campos internos, slugs manipulados, leitura sem escrita, número de consultas,
limite 429) e `tests/Feature/Academy/MediaApiTest.php` (matriz de visibilidade da imagem, capa,
editores, órfão, arquivo ausente ou alterado, extensão trocada, nomes manipulados, ausência de
cache e de 304, inclusive depois de arquivar o conteúdo ou entre editor e visitante).

## Etapa 2.3 — Telas do catálogo e da aula (implementada)

Rotas públicas (grupo `(marketing)` do Next, sem login): `/academy` (catálogo, filtro `level` e
página), `/academy/cursos/{curso}` e `/academy/cursos/{curso}/aulas/{aula}`. Dados só da API real
(2.2), lidos no servidor pelo `serverFetch` existente e validados por Zod.

Estrutura em `apps/web/src/features/academy`:

| Pasta/arquivo | Papel |
|---|---|
| `api/catalog.ts` | leitura no servidor: filtros e slugs conferidos antes da URL da API, Zod, `not_found`/`unavailable`, `cache()` (página e metadados numa chamada); nunca `include_unpublished` |
| `lib/schemas.ts`, `lib/markdown-rules.ts` | espelho Zod dos blocos v1 no formato resolvido da API e das regras do Markdown do PHP; testados com `content/academy/_fixtures/blocks-v1.json` |
| `components/safe-markdown.tsx` | `react-markdown` sem HTML (`skipHtml`, sem `rehype-raw`), lista fechada de elementos, links filtrados, títulos a partir de `h2` |
| `components/blocks/*` | um renderizador por tipo: Markdown, callout, imagem (URL da API, `alt`, decorativa, falha), vídeo (clique para carregar, `youtube-nocookie`, `sandbox`), quiz_ref (só o resumo) |
| `components/catalog.tsx`, `course-overview.tsx`, `lesson-view.tsx` | telas, estados (carregando, vazio, filtro vazio, página inexistente, indisponível) e navegação |

### Etapa 2.3 — ajustes feitos na implementação

| # | Ponto | Implementado | Motivo |
|---|---|---|---|
| C1 | URL das telas | `/academy/cursos/{curso}/aulas/{aula}` | O padrão previsto no modelo de dados |
| C2 | Biblioteca de Markdown | `react-markdown` 10 (só CommonMark, sem `rehype-raw`/GFM) | Consolidada, renderiza no servidor sem HTML; GFM traria tabelas e autolinks que o importador não prevê |
| C3 | Fixtures compartilhadas | `./content` montado só leitura em `/content` no container `web` de desenvolvimento | O Vitest lê `../../content/academy/_fixtures` pelo mesmo caminho no host, no container e na CI (como A4 fez para a API) |
| C4 | Vídeo | `iframe` só depois do clique | Nenhuma requisição ao YouTube só por abrir a aula; mesma CSP |
| C5 | Imagens | `<img>` com a URL da API, sem `next/image` | O otimizador do Next buscaria a imagem no servidor, sem a sessão, e guardaria cópia; a API manda `no-store` |
| C6 | 404 | `notFound()` dentro do Suspense: corpo e `noindex` corretos, status **200** | Comportamento do Next com PPR (o stream já começou); igual às demais páginas do projeto |
| C7 | E2E | Conteúdo próprio em `apps/web/e2e/fixtures/academy`, importado pelo `scripts/e2e.sh`; mídia em `tmpfs` | Testes independentes do conteúdo real e do banco de desenvolvimento |
| C8 | Modo editorial | Fora das telas | Ver rascunhos pela interface seria um CMS/preview, fora da 2.3; a API continua sendo a única que decide |
| C9 | Navegação | Link "Academy" no cabeçalho público e no da área logada | Acesso ao catálogo de qualquer página |

Riscos remanescentes: CSP com `'unsafe-inline'` (ver
[seguranca-http.md](../architecture/seguranca-http.md#conteúdo-editorial-da-academy-etapa-23-com-unsafe-inline));
404 com status 200 (C6); no E2E sem borda, todas as leituras do servidor chegam à API com o IP do
container `web` (o limite `academy-public` é compartilhado); em produção a borda define o IP real
(P1).

## Etapas seguintes (resumo)

| Etapa | Entrega |
|---|---|
| 2.2 | API pública do catálogo e das aulas, rotas na lista pública, `academy-public`, servir imagens do disco `academy` |
| 2.3 | Telas do catálogo e da aula, blocos (Zod + componentes), Markdown seguro, CSP por rota para vídeo, axe nos dois temas |
| 2.4 | `Learning`: matrícula, conclusão, progresso, "Meu progresso" com histórico de arquivados; Policies e matriz |
| 2.5 | Quizzes: tentativa, snapshot, envio, correção, liberação do gabarito (R20), histórico, limites |
| 2.6 | Painel: cartão da Academy com "continuar de onde parou" |
| 2.7 | Revisão transversal de segurança, acessibilidade e desempenho |
| 2.8 | Auditoria e fechamento da Fase 2 |
