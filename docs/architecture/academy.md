# Academy e Learning: regras de negócio e autorização

> Definido na etapa 2.0 (aprovado em 2026-10-17). Decisões: ADRs
> [0015](../decisions/0015-academy-e-learning-publicacao-e-visibilidade.md),
> [0016](../decisions/0016-schemas-de-blocos-e-importacao-de-conteudo.md),
> [0017](../decisions/0017-progresso-diante-de-mudancas-no-conteudo.md),
> [0018](../decisions/0018-quizzes-tentativas-e-historico.md) e
> [0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md). Dados:
> [academy-learning.md](../database/academy-learning.md). API: [academy-v1.md](../api/academy-v1.md).

## Regras de negócio

### Publicação e visibilidade

| # | Regra |
|---|---|
| R1 | Estados: `draft` → `published` → `archived`. `archived` → `published` é permitido (republicar). `published` → `draft` **não**: uma vez público, só arquiva. |
| R2 | Item público ⇔ ele **e** todos os ancestrais estão `published` (curso, módulo, aula). |
| R3 | Rascunho e arquivado: 404 para quem não tem `manage-academy-content` ou não pediu `include_unpublished=true`. |
| R4 | A ordem exibida é a de `position`; o catálogo lista cursos por `position`. |
| R5 | Curso publicado precisa de ao menos um módulo publicado com uma aula publicada (validado na importação). |
| R6 | Conteúdo publicado não é apagado (ADR 0015); o importador nunca apaga nem arquiva implicitamente (ADR 0016). |

### Matrícula e progresso

| # | Regra |
|---|---|
| R7 | Matricular-se exige login e um curso **público**. Matricular de novo é idempotente (devolve a matrícula existente). |
| R8 | Concluir uma aula exige login e uma aula **pública**; se o jogador não está matriculado no curso, a matrícula é criada na mesma transação (auto-matrícula). |
| R9 | Concluir de novo é idempotente (mantém a primeira data). Desfazer conclusão não existe na Fase 2. |
| R10 | Ler uma aula **não grava nada** (a leitura é pública e cacheável). Progresso só muda por ação explícita: concluir a aula (`completed`) ou iniciar o quiz dela (`in_progress`, se ainda não concluída). `enrollments.last_activity_at` nasce na matrícula e muda com uma conclusão **nova** de aula, uma tentativa de quiz **nova** (devolver a tentativa já aberta não conta, R15) e o envio de uma tentativa; repetir uma ação idempotente não muda nada. "Continuar de onde parou" = primeira aula pública não concluída do curso com atividade mais recente. |
| R11 | Percentual do curso = aulas públicas concluídas ÷ aulas públicas (ADR 0017). |
| R12 | `enrollments.completed_at` é gravado ao concluir a última aula pública e não é apagado depois, nem se aulas forem publicadas ou arquivadas. |
| R13 | O jogador só vê o próprio progresso. Nenhum papel de plataforma (inclusive `admin`) lê progresso de outros na Fase 2. |
| R13a | **Conteúdo arquivado** (P1): ninguém sem `manage-academy-content` lê a aula, nem quem estava matriculado; concluir, iniciar tentativa ou enviar respostas respondem 404. O histórico (conclusões, tentativas, notas e cursos concluídos) continua em `/me/enrollments` e `/me/quiz-attempts`. |

### Quizzes

| # | Regra |
|---|---|
| R14 | Iniciar tentativa exige login e aula **pública**; com auto-matrícula como em R8. |
| R15 | Uma tentativa aberta por vez; iniciar com uma aberta devolve a aberta (200), sem consumir tentativa. |
| R16 | `max_attempts` conta tentativas **enviadas e abertas**; esgotadas, iniciar responde 409 `quiz_attempts_exhausted`. A revisão do quiz mudar **não** reinicia a contagem (P2); avaliação substancialmente nova é outro quiz. |
| R16a | Aprovado, não há tentativa nova daquele quiz: iniciar responde 409 `quiz_already_passed`. A aprovação vale mesmo depois de mudanças de revisão; novas tentativas só num **outro quiz** (outra `key`). (ADR 0018, decisão derivada da P7, confirmada.) |
| R17 | Enviar respostas só da própria tentativa, aberta, de aula pública; todas as perguntas do snapshot respondidas; opções precisam existir no snapshot; `single_choice` com exatamente uma opção. |
| R18 | Correção no servidor, contra o snapshot; aprovação = `score_percent ≥ pass_percent` (ADR 0018). |
| R19 | Reprovar não bloqueia aulas, conclusão nem o curso. |
| R20 | **Revisão liberada** = aprovado em alguma tentativa do quiz **ou** tentativas esgotadas; calculada no servidor. |
| R20a | Antes da liberação: nota, aprovado/reprovado, tentativas restantes e acerto/erro por pergunta. **Nunca** opções corretas nem explicações, em nenhuma resposta da API (início, envio, histórico). |
| R20b | Depois da liberação: gabarito e explicações do **snapshot de cada tentativa** enviada. Um único componente da API aplica R20–R20b a todas as respostas sobre tentativas. |

## Matriz de autorização

Legenda: ✔ permitido · — negado (401 sem sessão; 404 para conteúdo não público; 403 para os
demais casos) · "próprio" = só os dados do próprio usuário.

| Ação | Visitante | `user` | `editor` | `admin` |
|---|---|---|---|---|
| Listar catálogo público | ✔ | ✔ | ✔ | ✔ |
| Ver curso / aula públicos | ✔ | ✔ | ✔ | ✔ |
| Ver rascunho ou arquivado (`include_unpublished`) | — (404) | — (404) | ✔ | ✔ |
| Matricular-se | — (401) | ✔ | ✔ | ✔ |
| Ver matrículas e progresso | — (401) | próprio | próprio | próprio |
| Concluir aula | — (401) | próprio | próprio | próprio |
| Iniciar tentativa / enviar respostas | — (401) | próprio | próprio | próprio |
| Ver tentativas (gabarito só com revisão liberada, R20) | — (401) | próprio | próprio | próprio |
| Ver histórico de conteúdo arquivado | — (401) | próprio | próprio | próprio |
| Ler aula arquivada | — (404) | — (404) | ✔ (`include_unpublished`) | ✔ (`include_unpublished`) |
| Ver progresso de outro jogador | — | — | — | — |
| Importar conteúdo | terminal do servidor (sem HTTP) | | | |

Implementação (ADR 0012): `manage-academy-content` só para `include_unpublished` (na 2.2, pelo
Gate dentro das rotas públicas, `App\Modules\Academy\Http\EditorialAccess`: a rota não exige
sessão, então não usa o middleware `can:`); Policies
`EnrollmentPolicy`, `LessonProgressPolicy` e `QuizAttemptPolicy` decidem por **posse**. Tentativa
de outro jogador responde **404** (não revela que existe). Cada linha da matriz vira teste
(todos os papéis × ação). E-mail confirmado não é exigido (decisão P3).

## Limites de requisição

| Grupo | Limite | Chave | Rotas |
|---|---|---|---|
| `academy-public` | 120/min | IP real (ADR 0009) | leitura do catálogo e das aulas |
| `academy-media` | 600/min | IP real (ADR 0009) | imagens do catálogo e das aulas (etapa 2.2: uma página carrega várias de uma vez) |
| `api` (existente) | 120/min | usuário | leituras autenticadas de progresso e tentativas |
| `learning-write` | 30/min | usuário | matrícula e conclusão de aula (ler uma aula não grava nada, R10) |
| `quiz-attempt` | 10/min | usuário | iniciar tentativa e enviar respostas |

Limites de escrita protegem contra automação; o de quiz também dificulta adivinhar o gabarito
(além do `max_attempts`). Toda rota que altera estado tem `throttle:` (teste de rotas existente).

## Segurança e acessibilidade por funcionalidade

Entram **junto** com cada funcionalidade (etapas 2.1 a 2.6), não só na revisão transversal (2.7)
e na auditoria (2.8):

| Funcionalidade | Segurança | Acessibilidade |
|---|---|---|
| Importador (2.1) | validação estrita dos blocos; Markdown com HTML recusado; imagens checadas pelo conteúdo e endereçadas por hash; arquivos gravados antes da transação, referências só a arquivos conferidos; `academy:media-prune` para órfãos; *advisory lock* | `alt` obrigatório em imagens; `title` obrigatório em vídeos |
| Catálogo (2.2/2.3) | escopo de visibilidade; 404 para não público; limite por IP | estrutura de títulos (`h1` da página, `h2` nos blocos); axe nos dois temas |
| Aula (2.3) | Markdown sanitizado; CSP por rota para vídeo; links externos com `noopener` | `iframe` com `title`; imagens com `alt`; foco e navegação por teclado |
| Progresso (2.4) | Policies de posse; idempotência; limites | anúncio do progresso (`aria-live`), botões com nome acessível |
| Quizzes (2.5) | gabarito só no servidor; snapshot; limites; 404 para tentativa alheia | `fieldset`/`legend`, grupos de rádio/checkbox, resultado anunciado |
| Painel e navegação (2.6) | sessão resolvida no servidor; shell estático (PPR) sem dados da conta; sessão desconhecida sem dados nem "Entrar"; leituras `no-store` | marcos e nomes de navegação únicos, `progress` com `aria-valuetext`, estados com `role="status"`/`role="alert"`, menu do celular com foco preso; axe nos dois temas |
