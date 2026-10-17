# Modelo de dados — Academy e Learning (Fase 2)

> Definido na etapa 2.0 (aprovado em 2026-10-17). Tabelas da Academy criadas na etapa 2.1
> (`database/migrations/2026_10_17_1000*` a `1004*`); as do Learning chegam na 2.4. Princípios gerais em
> [modelo-conceitual.md](modelo-conceitual.md). Decisões: ADRs
> [0015](../decisions/0015-academy-e-learning-publicacao-e-visibilidade.md) a
> [0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md).

Convenções desta página:

- `id`: UUIDv7 (`uuid`), chave primária. `created_at`/`updated_at`: `timestamptz`.
- Enums como `string` + **check constraint**.
- `key`: chave imutável do arquivo de conteúdo (ADR 0016), formato `^[a-z0-9]+(?:-[a-z0-9]+)*$`,
  até 80 caracteres.
- `slug`: mesmo formato, até 80 caracteres, editável; unicidade **adiável**, para a importação
  poder trocar slugs entre dois itens numa transação.
- `status`: `draft`, `published`, `archived` (padrão `draft`).
- Posições: inteiro ≥ 0; unicidade **adiável** (`DEFERRABLE INITIALLY DEFERRED`) para permitir
  reordenar numa transação.

## Academy (catálogo)

### `academy_media`

Registro das imagens gravadas no disco `academy` ([ADR 0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md)).
Uma linha só é criada na transação da importação, **depois** que o arquivo existe e teve o hash
conferido; arquivos sem linha são órfãos e podem ser removidos por `academy:media-prune`.

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `sha256` | char(64) | **único**; hexadecimal minúsculo (check) |
| `path` | varchar(255) | único; `media/{sha256[0:2]}/{sha256}.{ext}`, relativo à raiz do disco `academy` (`storage/app/academy`); check confere o caminho com `sha256` e `mime` |
| `mime` | varchar(16) | check `image/png`, `image/jpeg`, `image/webp`, `image/avif` |
| `bytes` | integer | check 1..2 097 152 |
| `width` / `height` | smallint | check 1..4096 |
| `created_at` | timestamptz | não nulo (sem `updated_at`: registro imutável) |

Blocos `image` guardam o `sha256` da mídia; o importador garante, na mesma transação, que ela
existe em `academy_media`. Mídia registrada não é apagada na Fase 2.

### `courses`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `key` | varchar(80) | único; imutável |
| `slug` | varchar(80) | único adiável |
| `title` | varchar(160) | não vazio |
| `summary` | varchar(500) | não vazio |
| `level` | varchar(16) | check `beginner`, `intermediate`, `advanced` |
| `status` | varchar(16) | check dos três estados |
| `position` | integer | ordem no catálogo; único adiável |
| `cover_media_id` | uuid | nulo; FK `academy_media` ON DELETE RESTRICT |
| `published_at` / `archived_at` | timestamptz | nulos; preenchidos na transição |

Índices: `(status, level, position)` para o catálogo.
Check: `status = 'published'` ⇒ `published_at` não nulo; `status = 'archived'` ⇒ `archived_at`
não nulo.

### `course_modules`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `course_id` | uuid | FK `courses` **ON DELETE RESTRICT** |
| `key` | varchar(80) | único por curso `(course_id, key)` |
| `slug` | varchar(80) | único adiável por curso `(course_id, slug)` |
| `title` | varchar(160) | não vazio |
| `summary` | varchar(500) | nulo |
| `position` | integer | único adiável `(course_id, position)` |
| `status`, `published_at`, `archived_at` | | como em `courses` |

Único adicional `(id, course_id)`, alvo da FK composta das aulas.

### `lessons`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `course_id` | uuid | FK `courses` ON DELETE RESTRICT |
| `module_id` | uuid | FK composta `(module_id, course_id)` → `course_modules (id, course_id)` ON DELETE RESTRICT: a aula sempre pertence a um módulo **do mesmo curso** |
| `key` | varchar(80) | único `(course_id, key)` |
| `slug` | varchar(80) | único adiável `(course_id, slug)` (URL `/academy/cursos/{curso}/aulas/{aula}`) |
| `title` | varchar(160) | não vazio |
| `summary` | varchar(500) | nulo |
| `position` | integer | único adiável `(module_id, position)` |
| `estimated_minutes` | smallint | nulo; check 1..600 |
| `content` | jsonb | não nulo; `{"version": n, "blocks": [...]}`; check `jsonb_typeof(content->'blocks') = 'array'` |
| `content_version` | smallint | não nulo; igual a `content->>'version'` (check) |
| `content_hash` | char(64) | SHA-256 do conteúdo canônico |
| `status`, `published_at`, `archived_at` | | como em `courses` |

Índices: `(module_id, position)`, `(course_id, status)`. Índice GIN em `content` só se surgir
consulta por bloco (ex.: aulas que usam um quiz); não na 2.1.

A `key` é única **por curso**: mover o arquivo de uma aula para outro módulo do mesmo curso mantém
o registro (muda `module_id`); movê-la para outro curso cria outra aula, e a antiga fica ausente.

### `quizzes`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `lesson_id` | uuid | FK `lessons` ON DELETE RESTRICT; único parcial `(lesson_id) WHERE retired_at IS NULL` (0..1 quiz **ativo** por aula) |
| `key` | varchar(80) | único global (referenciado por `quiz_ref`) |
| `title` | varchar(160) | não vazio |
| `max_attempts` | smallint | padrão 3; check 1..20 |
| `pass_percent` | smallint | padrão 70; check 1..100 |
| `revision` | integer | padrão 1; check ≥ 1 |
| `retired_at` | timestamptz | nulo; quiz retirado da aula (ou com `key` trocada) que já teve tentativa |

Sem `status`: segue a aula (ADR 0015). O único **parcial** em `lesson_id` (e não um único simples,
como previsto na 2.0) é o que permite a aula receber um quiz novo quando o anterior já tem
tentativas: o antigo é aposentado e continua respondendo pelos snapshots (ADR 0018, item 8).
`revision` sobe quando muda o que afeta a correção (regras, perguntas, opções, gabarito, ordem);
o título não conta.

### `quiz_questions`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `quiz_id` | uuid | FK `quizzes` ON DELETE RESTRICT |
| `key` | varchar(80) | único `(quiz_id, key)` |
| `kind` | varchar(16) | check `single_choice`, `multiple_choice` |
| `prompt` | text | não vazio; até 1000 caracteres (check `char_length`) |
| `explanation` | text | nulo; até 2000 |
| `position` | integer | único parcial `(quiz_id, position) WHERE retired_at IS NULL` |
| `retired_at` | timestamptz | nulo; aposentada não aparece em tentativas novas |

### `quiz_options`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `question_id` | uuid | FK `quiz_questions` ON DELETE RESTRICT |
| `key` | varchar(80) | único `(question_id, key)` |
| `text` | varchar(500) | não vazio |
| `is_correct` | boolean | não nulo |
| `position` | integer | único parcial `(question_id, position) WHERE retired_at IS NULL` |
| `retired_at` | timestamptz | nulo |

Regras entre linhas (validadas pelo importador, numa transação): pergunta ativa com 2 a 8 opções
ativas; `single_choice` com exatamente 1 correta; `multiple_choice` com pelo menos 1 correta.

## Learning (dados do jogador)

### `enrollments`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `user_id` | uuid | FK `users` **ON DELETE CASCADE** |
| `course_id` | uuid | FK `courses` ON DELETE RESTRICT |
| `enrolled_at` | timestamptz | não nulo |
| `completed_at` | timestamptz | nulo; não é apagado depois (ADR 0017) |
| `last_activity_at` | timestamptz | não nulo |

Único `(user_id, course_id)`. Índices `(user_id, last_activity_at desc)` ("continuar de onde
parou") e `(course_id)`.

### `lesson_progress`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `user_id` | uuid | FK `users` ON DELETE CASCADE |
| `lesson_id` | uuid | FK `lessons` ON DELETE RESTRICT |
| `course_id` | uuid | FK `courses` ON DELETE RESTRICT (desnormalizado para consultas por curso) |
| `status` | varchar(16) | check `in_progress`, `completed` |
| `started_at` | timestamptz | não nulo |
| `completed_at` | timestamptz | nulo; check `status = 'completed'` ⇔ não nulo |
| `completed_content_hash` | char(64) | nulo; hash da aula na conclusão (ADR 0017) |

Único `(user_id, lesson_id)`. Índice `(user_id, course_id)`. A coerência `lesson.course_id =
course_id` é garantida por FK composta `(lesson_id, course_id)` → `lessons (id, course_id)`
(único adicional em `lessons`).

### `quiz_attempts`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `user_id` | uuid | FK `users` ON DELETE CASCADE |
| `quiz_id` | uuid | FK `quizzes` ON DELETE RESTRICT |
| `attempt_number` | smallint | ≥ 1; único `(user_id, quiz_id, attempt_number)` |
| `quiz_revision` | integer | revisão do quiz na abertura |
| `status` | varchar(16) | check `in_progress`, `submitted` |
| `snapshot` | jsonb | perguntas, opções, ordem, **gabarito e explicações** congelados na abertura; gabarito e explicações só saem da API com a revisão liberada (ADR 0018) |
| `started_at` | timestamptz | não nulo |
| `submitted_at` | timestamptz | nulo; check `status = 'submitted'` ⇔ não nulo |
| `question_count` / `correct_count` | smallint | nulos até a correção |
| `score_percent` | numeric(5,2) | nulo até a correção; check 0..100 |
| `passed` | boolean | nulo até a correção |

Único parcial `(user_id, quiz_id) WHERE status = 'in_progress'` (uma tentativa aberta por vez).

A **revisão liberada** (aprovado em alguma tentativa ou tentativas esgotadas) não é coluna: é
calculada a partir das tentativas do jogador naquele quiz, para não haver estado divergente.

### `quiz_attempt_answers`

| Coluna | Tipo | Regras |
|---|---|---|
| `id` | uuid | PK |
| `attempt_id` | uuid | FK `quiz_attempts` ON DELETE CASCADE |
| `question_id` | uuid | FK `quiz_questions` ON DELETE RESTRICT |
| `selected_option_ids` | uuid[] | não vazio |
| `is_correct` | boolean | não nulo |

Único `(attempt_id, question_id)`. As opções escolhidas são validadas contra o `snapshot` da
tentativa (não contra o estado atual do quiz).

## Políticas de exclusão (resumo)

| Relação | ON DELETE | Motivo |
|---|---|---|
| conteúdo → conteúdo (curso → módulo → aula → quiz → pergunta → opção) | RESTRICT | conteúdo publicado é arquivado, não apagado |
| curso → `academy_media` (capa) | RESTRICT | mídia registrada não é apagada na Fase 2 |
| dado do jogador → `users` | CASCADE | excluir a conta apaga o histórico pessoal |
| dado do jogador → conteúdo | RESTRICT | o banco impede apagar conteúdo com histórico |
| resposta → tentativa | CASCADE | a resposta não existe sem a tentativa |

## Diagrama

```
academy_media 1─N courses (capa) ; blocos image → academy_media.sha256 (validado na importação)
courses 1─N course_modules 1─N lessons 1─0..1 quizzes 1─N quiz_questions 1─N quiz_options
users 1─N enrollments N─1 courses
users 1─N lesson_progress N─1 lessons
users 1─N quiz_attempts N─1 quizzes ; quiz_attempts 1─N quiz_attempt_answers N─1 quiz_questions
```
