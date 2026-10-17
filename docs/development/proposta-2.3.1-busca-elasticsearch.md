# Proposta — 2.3.1: busca avançada da Academy com Elasticsearch

> **Proposta, não aprovada.** Nada aqui está implementado nem instalado. Depende da etapa 2.2
> (API do catálogo) e deve virar ADR (0020) antes de qualquer código. A numeração "2.3.1" é
> provisória: no [plano da Fase 2](fase-2-plano.md) a 2.3 é a das telas; a busca faz mais sentido
> **depois** dela, porque precisa de uma tela de resultados.

## 1. Justificativa

| Necessidade | PostgreSQL (FTS) | Elasticsearch |
|---|---|---|
| Busca textual em português (radicais, acentos, stopwords) | `tsvector` com configuração `portuguese` + `unaccent` | analisador `portuguese` + `asciifolding` |
| Relevância ajustável (título vale mais que corpo, curso × aula) | `ts_rank` com pesos A–D, ajuste limitado | BM25, `boost` por campo, `function_score` |
| Autocomplete enquanto digita | `pg_trgm` / prefixo, razoável | `search_as_you_type` / edge n-gram, nativo |
| Sinônimos do jogo (smoke ↔ fumaça, molly ↔ molotov) | dicionário de tesauro, pouco prático | `synonym_graph` atualizável sem reindexar |
| Destaque do trecho encontrado | `ts_headline` (caro em textos longos) | `highlight` nativo |
| Facetas (nível, curso, tipo) | `GROUP BY` | agregações |

**Leitura honesta:** com o conteúdo atual (1 curso, 3 aulas), o FTS do PostgreSQL resolve tudo
sem infraestrutura nova. O Elasticsearch se justifica por **(a)** objetivo de aprendizado explícito
do projeto e **(b)** o crescimento previsto (lineups, mapas e estratégias nas fases seguintes),
que pede relevância, sinônimos e autocomplete de verdade. A arquitetura abaixo mantém a troca
barata: a busca fica atrás de um contrato e o PostgreSQL continua sendo a fonte.

## 2. Arquitetura proposta

```
content/academy ──academy:import──► PostgreSQL (fonte oficial)
                                       │ after commit
                                       ▼
                            Redis queue "search" ──► SyncCourseSearch (job idempotente)
                                                         │ bulk upsert / delete
                                                         ▼
                                                Elasticsearch (índice secundário)
                                                         ▲ consulta (só IDs + trechos)
GET /api/v1/academy/search ──► CatalogSearch ───────────┘
                                   │ IDs
                                   ▼
                        CatalogQuery::visible() no PostgreSQL ──► resposta (dados do banco)
```

- **PostgreSQL é a verdade**; o índice pode ser apagado e reconstruído a qualquer momento.
- **No módulo `Academy`** (a busca é sobre o catálogo): `App\Modules\Academy\Search`, com o
  contrato `CatalogSearch` e a implementação `Elasticsearch\ElasticsearchCatalogSearch`. Teste de
  arquitetura: só `Search\Elasticsearch` usa o cliente; controllers não o conhecem.
- **Cliente oficial** `elasticsearch/elasticsearch` (major igual ao do cluster), registrado como
  singleton no `AcademyServiceProvider`, configurado por `config/academy.php` (`search.*`).
- **Sem Laravel Scout**: os observers de model do Scout não enxergam a visibilidade efetiva
  (curso × módulo × aula) nem o fluxo em lote da importação; a sincronização explícita é mais
  simples de testar aqui.
- **A API confirma a visibilidade**: o Elasticsearch devolve IDs e trechos; o Laravel recarrega os
  itens com `visible()` e descarta o que não for mais público (índice atrasado nunca vaza).

## 3. Índice e mappings iniciais

Um índice com cursos e aulas (`kind`), lido e escrito por **aliases**: `academy-catalog`
(leitura) e `academy-catalog-write` (escrita); o índice físico é `academy-catalog-v{n}-{data}`.

```json
{
  "settings": {
    "number_of_shards": 1,
    "number_of_replicas": 0,
    "analysis": {
      "filter": {
        "pt_stop": { "type": "stop", "stopwords": "_portuguese_" },
        "pt_stem": { "type": "stemmer", "language": "light_portuguese" },
        "cs2_synonyms": { "type": "synonym_graph", "synonyms_path": "analysis/cs2-synonyms.txt", "updateable": true }
      },
      "analyzer": {
        "pt_index": { "tokenizer": "standard", "filter": ["lowercase", "asciifolding", "pt_stop", "pt_stem"] },
        "pt_search": { "tokenizer": "standard", "filter": ["lowercase", "asciifolding", "cs2_synonyms", "pt_stop", "pt_stem"] }
      }
    }
  },
  "mappings": {
    "dynamic": "strict",
    "properties": {
      "kind":              { "type": "keyword" },
      "course_id":         { "type": "keyword" },
      "course_slug":       { "type": "keyword" },
      "course_title":      { "type": "text", "analyzer": "pt_index", "search_analyzer": "pt_search" },
      "module_slug":       { "type": "keyword" },
      "module_title":      { "type": "text", "analyzer": "pt_index", "search_analyzer": "pt_search" },
      "slug":              { "type": "keyword" },
      "title": {
        "type": "text", "analyzer": "pt_index", "search_analyzer": "pt_search",
        "fields": { "suggest": { "type": "search_as_you_type", "analyzer": "pt_index" }, "raw": { "type": "keyword" } }
      },
      "summary":           { "type": "text", "analyzer": "pt_index", "search_analyzer": "pt_search" },
      "body":              { "type": "text", "analyzer": "pt_index", "search_analyzer": "pt_search" },
      "level":             { "type": "keyword" },
      "estimated_minutes": { "type": "integer" },
      "has_quiz":          { "type": "boolean" },
      "content_hash":      { "type": "keyword", "index": false },
      "published_at":      { "type": "date" },
      "indexed_at":        { "type": "date" }
    }
  }
}
```

- `_id` = UUID do curso/aula (estável: o importador mantém o registro pela `key`).
- `body`: texto puro extraído dos blocos `markdown` e `callout` (sem sintaxe), `title` dos
  vídeos e `alt` das imagens. **Nunca**: perguntas, opções, gabarito, explicações, snapshots,
  caminhos de arquivo, dados de usuários. O extrator é uma lista de permissões por tipo de bloco
  (tipo novo não entra até ser decidido) e tem teste que falha se aparecer campo de quiz.
- `dynamic: strict`: um campo novo sem mapping faz a indexação falhar, em vez de indexar algo por
  engano.
- Produção: `number_of_replicas: 1` com mais de um nó.

## 4. Sincronização

1. **Gatilho**: o `academy:import` já sabe o que mudou (relatório). Ao final da transação
   (`afterCommit`), despacha `SyncCourseSearch($courseId)` para cada curso criado, alterado,
   arquivado ou com filhos alterados. Sem importação, nada muda (o catálogo só é escrito por ela).
2. **Job idempotente**: lê o **estado atual** do curso no PostgreSQL (não o evento) e calcula o
   conjunto desejado de documentos: curso público → documento do curso + aulas públicas; o resto
   do curso → remoção. Aplica tudo num `_bulk` (upsert dos desejados, `delete` dos demais IDs do
   curso, obtidos por `course_id`). Rodar duas vezes produz o mesmo índice.
3. **Ordem e concorrência**: `ShouldBeUnique` por curso (com `uniqueFor`) e `WithoutOverlapping`
   pela mesma chave; como o job sempre lê o estado atual, um job atrasado não restaura dado velho.
4. **Retries**: `tries = 5`, `backoff = [10, 30, 120, 600]`, `timeout = 60`; esgotado, vai para
   `failed_jobs` com log. Fila própria (`search`), para não atrasar e-mails da fila padrão.
5. **Reconciliação**: `academy:search-reconcile` (agendado, por exemplo a cada hora) compara os
   IDs públicos do PostgreSQL com os do índice e corrige diferenças. Cobre evento perdido, worker
   parado e Elasticsearch fora do ar durante a importação.

### Remover rápido o que deixou de ser público

- O job de um curso arquivado ou com aula arquivada **remove primeiro** (delete antes do upsert),
  numa fila prioritária (`search-urgent`) e com `refresh=wait_for` nos deletes.
- **Garantia real**: a verificação no PostgreSQL a cada busca (seção 2). Mesmo com o índice
  atrasado, o item não público nunca sai na resposta; o pior caso é um resultado a menos.
- Trechos destacados vêm do índice: se o `content_hash` do documento diferir do banco, a resposta
  sai **sem** o trecho daquele item (evita mostrar texto antigo de uma aula editada).

## 5. Reindexação completa

`academy:search-reindex` (com o mesmo tipo de lock exclusivo dos comandos da Academy):

1. cria `academy-catalog-v{n+1}-{data}` com o mapping atual;
2. aponta o alias de escrita para **os dois** índices (jobs em andamento escrevem em ambos);
3. indexa do PostgreSQL em lotes (`lazyById`, `_bulk` de ~500 documentos);
4. `refresh`, confere contagem por `kind` contra o banco (diferença → aborta e mantém o antigo);
5. troca o alias de leitura numa única chamada `_aliases` (atômica) e tira o antigo da escrita;
6. remove o índice antigo depois de um período de retenção (`--keep` para manter).

Usos: mudança de mapping/analisador, índice corrompido, ambiente novo. Como o índice é
reconstruível, não precisa de snapshot/backup (opcional em produção, para acelerar a recuperação).

## 6. Segurança e autenticação

- **Rede**: Elasticsearch e Kibana só na rede interna; em desenvolvimento, nenhuma porta publicada
  por padrão (Kibana opcional em `127.0.0.1`). O navegador nunca fala com o Elasticsearch.
- **Segurança do cluster ligada** (padrão do 8.x+), inclusive em desenvolvimento: usuário e senha
  no `.env` (fora do repositório). Produção: TLS entre Laravel e cluster, verificação do
  certificado (CA própria ou gerenciada).
- **Privilégio mínimo** com API keys separadas:
  - API (leitura): `read` em `academy-catalog`;
  - worker (escrita): `create_index`, `write`, `delete`, `manage` só em `academy-catalog*` e
    `manage_aliases`;
  - nenhuma chave com privilégio de cluster no código da aplicação.
- **Consulta**: o texto do usuário entra só como valor de `simple_query_string`/`multi_match`
  (nunca DSL vinda de fora), limitado a 100 caracteres, sem curinga inicial; `size` ≤ 20,
  `from + size` ≤ 1000, `timeout` por consulta, `track_total_hits` limitado.
- **Erros**: mensagens do Elasticsearch nunca saem na resposta (só log), como no resto da API.
- **Limite** próprio (`academy-search`, por IP real), e o autocomplete com limite menor por
  requisição e debounce no frontend.
- **Dados**: só conteúdo público e editorial; nada pessoal (buscas não são gravadas por padrão; se
  um dia forem, sem identificar o usuário e com retenção curta).

## 7. Testes necessários

| Camada | O quê |
|---|---|
| Unitário (puro) | extrator de documento: aula pública → documento; rascunho/arquivado → nenhum; **nenhum campo de quiz** em nenhum caso (teste de chaves); Markdown vira texto sem sintaxe |
| Feature com cliente falso | job idempotente (duas execuções = mesmo resultado); curso arquivado gera só deletes; índice "atrasado" devolvendo ID de rascunho → API descarta; `content_hash` diferente → sem trecho; Elasticsearch indisponível → comportamento da seção 8 |
| Integração com Elasticsearch real (`@group elasticsearch`) | mapping aceito; analisador (acentos, radicais, sinônimos); relevância (título > corpo); autocomplete; reindexação com troca de alias sem janela vazia; reconciliação |
| Segurança | `q` com DSL/JSON/curingas não altera a consulta; limites de tamanho e paginação; rota de busca na lista pública do `RouteAuthorizationTest`; nenhum header/mensagem do Elasticsearch na resposta |
| Arquitetura | só `Search\Elasticsearch` usa o cliente; Academy continua sem depender de Learning/Identity |
| E2E (com a tela) | buscar, filtrar, autocomplete por teclado e leitor de tela; busca fora do ar não quebra a página |

Na CI: suíte normal com o cliente falso; job separado (ou `services:` do GitHub Actions) com
Elasticsearch real para o grupo de integração.

## 8. Indisponibilidade do mecanismo de busca

- Timeouts curtos (conexão 1 s, consulta 2 s) e *circuit breaker* simples: depois de N falhas, um
  flag no Redis desliga as chamadas por 30 s.
- **Busca**: cai para uma busca simples no PostgreSQL (título e resumo, `ILIKE`/FTS), com
  `meta.degraded: true`; ou **503** `search_unavailable` — decisão pendente (recomendo o
  fallback, por ser barato com o volume atual).
- **Autocomplete**: devolve lista vazia (200) com `meta.degraded: true`.
- **Sincronização**: os jobs falham e são repetidos; a reconciliação corrige o resto. A importação
  nunca falha por causa do Elasticsearch.
- `/api/v1/health` **não** passa a depender do Elasticsearch (busca é recurso degradável); um
  `system:check-search` separado informa o estado.

## 9. Infraestrutura

Desenvolvimento (`compose.yaml`, perfis opcionais `search` e `kibana`, desligados por padrão):

- `elasticsearch` (imagem oficial, tag exata fixada), `discovery.type=single-node`, segurança
  ligada, heap `-Xms512m -Xmx512m` (≈1–1,5 GB de RAM no total), volume próprio, healthcheck;
- `kibana` (mesma versão) só em `127.0.0.1:5601`, para explorar índices e consultas;
- **WSL2**: o Elasticsearch exige `vm.max_map_count ≥ 262144` (configurar no WSL e documentar em
  [wsl2.md](wsl2.md); o `make doctor` passa a conferir quando o perfil estiver ativo);
- worker consumindo também as filas `search` e `search-urgent`.

Produção: cluster gerenciado ou VM dedicada em rede privada, TLS, API keys, monitoramento de
heap/disco, alerta de jobs falhos. Entra na lista de [bloqueios de produção](../deploy/bloqueios-de-producao.md)
como item novo se for adotado.

## 10. Custos operacionais e alternativas

| Opção | Custo/complexidade | Quando faz sentido |
|---|---|---|
| **PostgreSQL FTS** (`tsvector` + `unaccent` + `pg_trgm`) | zero infraestrutura nova; índice GIN no próprio banco | volume atual e próximo; sem requisito forte de relevância/sinônimos |
| **Elasticsearch** self-hosted | VM com ≥ 4 GB de RAM, atualizações de versão, monitoramento | aprendizado + catálogo grande, relevância e sinônimos |
| **Elasticsearch gerenciado** (Elastic Cloud) | menos operação, custo mensal maior (conferir preços atuais) | produção sem equipe de infraestrutura |
| **OpenSearch** (fork, AWS gerenciado) | parecido com o Elasticsearch; cliente e API divergem aos poucos | quem já está na AWS |
| **Meilisearch / Typesense** | binário leve, tolerância a erros de digitação pronta | busca de produto simples, menos controle de relevância |

Licença: o Elasticsearch é distribuído também sob AGPLv3 (além de SSPL/ELv2); uso interno
self-hosted não gera obrigação para o CS Tactical, mas a escolha deve constar na ADR.

Recomendação: aprovar o Elasticsearch como **etapa de aprendizado com saída barata** — contrato
`CatalogSearch` com implementação PostgreSQL (fallback) e Elasticsearch, para que desligar o
Elasticsearch nunca derrube o produto.

## 11. Ordem de implementação

1. **ADR 0020** (decisão de busca, alternativas, licença) e revisão desta proposta.
2. Infraestrutura de desenvolvimento: perfis `search`/`kibana`, `vm.max_map_count` no WSL,
   variáveis no `.env.example`, `system:check-search`.
3. Contrato `CatalogSearch` + extrator de documentos (puro) + testes unitários e de arquitetura.
4. Mapping versionado no repositório + `academy:search-reindex` com aliases + testes de
   integração.
5. Sincronização: jobs após a importação, filas `search`/`search-urgent`, reconciliação agendada.
6. Endpoints `GET /api/v1/academy/search` e `/search/suggest`: verificação no PostgreSQL,
   fallback, limite `academy-search`, contrato em `academy-v1.md`, lista pública de rotas.
7. Tela de busca no frontend (depois das telas da 2.3), com E2E e acessibilidade.
8. Observabilidade: logs estruturados, jobs falhos, latência das consultas.
