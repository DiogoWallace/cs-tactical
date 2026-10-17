# API da Academy e do Learning — contratos iniciais (v1)

> Definido na etapa 2.0 (aprovado em 2026-10-17); nada implementado ainda. Convenções gerais
> (formato, erros, códigos) em [convencoes.md](convencoes.md). Regras e autorização em
> [academy.md](../architecture/academy.md).

## Visão geral

| Método | Caminho | Auth | Limite | Etapa |
|---|---|---|---|---|
| GET | `/api/v1/academy/courses` | não | `academy-public` | 2.2 |
| GET | `/api/v1/academy/courses/{course}` | não | `academy-public` | 2.2 |
| GET | `/api/v1/academy/courses/{course}/lessons/{lesson}` | não | `academy-public` | 2.2 |
| GET | `/api/v1/me/enrollments` | sim | `api` | 2.4 |
| PUT | `/api/v1/academy/courses/{course}/enrollment` | sim (+CSRF) | `learning-write` | 2.4 |
| GET | `/api/v1/academy/courses/{course}/progress` | sim | `api` | 2.4 |
| PUT | `/api/v1/academy/courses/{course}/lessons/{lesson}/completion` | sim (+CSRF) | `learning-write` | 2.4 |
| POST | `/api/v1/academy/quizzes/{quiz}/attempts` | sim (+CSRF) | `quiz-attempt` | 2.5 |
| POST | `/api/v1/academy/quiz-attempts/{attempt}/submission` | sim (+CSRF) | `quiz-attempt` | 2.5 |
| GET | `/api/v1/academy/quiz-attempts/{attempt}` | sim | `api` | 2.5 |
| GET | `/api/v1/me/quiz-attempts?quiz={key}` | sim | `api` | 2.5 |

`{course}` e `{lesson}` são **slugs**; `{quiz}` é a `key` do quiz; `{attempt}` é UUID. As rotas
públicas entram na lista explícita de `RouteAuthorizationTest`.

## Catálogo (público)

### `GET /api/v1/academy/courses`

Query: `level` (`beginner`, `intermediate`, `advanced`, opcional), `page` (≥ 1). 20 por página.

```json
{
  "data": [
    {
      "slug": "fundamentos-cs2",
      "title": "Fundamentos do CS2",
      "summary": "Movimentação, mira e economia para quem está começando.",
      "level": "beginner",
      "cover_image_url": "/media/academy/3f/3f9c...e1.webp",
      "module_count": 3,
      "lesson_count": 12,
      "estimated_minutes": 95
    }
  ],
  "links": { "...": "..." },
  "meta": { "current_page": 1, "per_page": 20, "total": 1 }
}
```

Só cursos públicos (ADR 0015). `include_unpublished=true` com `manage-academy-content` inclui
rascunhos e arquivados, com o campo `status`; sem a capacidade, o parâmetro é ignorado.

### `GET /api/v1/academy/courses/{course}`

```json
{
  "data": {
    "slug": "fundamentos-cs2",
    "title": "Fundamentos do CS2",
    "summary": "...",
    "level": "beginner",
    "cover_image_url": "...",
    "modules": [
      {
        "slug": "movimentacao",
        "title": "Movimentação",
        "lessons": [
          { "slug": "counter-strafe", "title": "Counter-strafe", "estimated_minutes": 8, "has_quiz": true }
        ]
      }
    ]
  }
}
```

Não público: **404** `not_found`. Sem dados de progresso (vêm de `/progress`, para a resposta
pública ser igual para todos e cacheável).

### `GET /api/v1/academy/courses/{course}/lessons/{lesson}`

```json
{
  "data": {
    "slug": "counter-strafe",
    "title": "Counter-strafe",
    "summary": null,
    "estimated_minutes": 8,
    "course": { "slug": "fundamentos-cs2", "title": "Fundamentos do CS2" },
    "module": { "slug": "movimentacao", "title": "Movimentação" },
    "content": {
      "version": 1,
      "blocks": [
        { "type": "markdown", "data": { "text": "## Por que parar antes de atirar\n..." } },
        { "type": "video", "data": { "provider": "youtube", "video_id": "AbCdEfGhIjK", "title": "Demonstração do counter-strafe", "start_seconds": 0 } },
        { "type": "image", "data": { "url": "/media/academy/ab/ab12...9f.webp", "alt": "Gráfico de velocidade ao soltar e apertar a tecla oposta", "caption": null, "width": 1280, "height": 720 } },
        { "type": "quiz_ref", "data": { "quiz": "counter-strafe-quiz", "title": "Teste rápido", "question_count": 4, "max_attempts": 3, "pass_percent": 70 } }
      ]
    },
    "content_hash": "9b1e...c4",
    "previous": null,
    "next": { "slug": "peek-seguro", "title": "Peek seguro" }
  }
}
```

A API **resolve** as referências: `image.src` (caminho no repositório) vira `url` pública com
dimensões; `quiz_ref` traz os dados resumidos do quiz, **sem perguntas nem gabarito**.
`previous`/`next` consideram só aulas públicas.

## Learning (autenticado)

### `PUT /api/v1/academy/courses/{course}/enrollment`

Sem corpo. **201** na primeira matrícula, **200** se já existia (idempotente).

```json
{ "data": { "course": "fundamentos-cs2", "enrolled_at": "2026-11-02T13:05:00Z", "completed_at": null } }
```

Curso não público: 404. Sem sessão: 401.

### `GET /api/v1/me/enrollments`

Lista as matrículas do usuário, mais recentes primeiro, com progresso resumido:

```json
{
  "data": [
    {
      "course": { "slug": "fundamentos-cs2", "title": "Fundamentos do CS2", "status": "published" },
      "enrolled_at": "...",
      "completed_at": null,
      "last_activity_at": "...",
      "progress": { "completed_lessons": 5, "total_lessons": 12, "percent": 41.67 },
      "next_lesson": { "slug": "peek-seguro", "title": "Peek seguro" }
    }
  ]
}
```

Cursos arquivados aparecem com `status: "archived"` e sem `next_lesson` (ADR 0017).

### `GET /api/v1/academy/courses/{course}/progress`

```json
{
  "data": {
    "enrolled": true,
    "completed_at": null,
    "percent": 41.67,
    "lessons": [
      { "slug": "counter-strafe", "status": "completed", "completed_at": "...", "updated_since_completion": false, "quiz": { "attempts_used": 1, "best_score_percent": 75.0, "passed": true } }
    ]
  }
}
```

Não matriculado: `enrolled: false` e lista vazia (200). `updated_since_completion` compara
`content_hash` (ADR 0017). Curso não público (rascunho ou arquivado): **404**; o histórico de
cursos arquivados fica em `/me/enrollments` e `/me/quiz-attempts` (R13a).

### `PUT /api/v1/academy/courses/{course}/lessons/{lesson}/completion`

Sem corpo. Conclui a aula (idempotente; auto-matrícula, R8). **200** com o progresso do curso
(como em `/progress`). Aula não pública: 404.

## Quizzes (autenticado)

### `POST /api/v1/academy/quizzes/{quiz}/attempts`

Sem corpo. **201** com a tentativa nova ou **200** com a aberta (R15). Perguntas na ordem do
snapshot, **sem gabarito**:

```json
{
  "data": {
    "id": "019a2c...",
    "attempt_number": 1,
    "max_attempts": 3,
    "status": "in_progress",
    "questions": [
      { "id": "019a2b...", "kind": "single_choice", "prompt": "Qual tecla...", "options": [ { "id": "019a2b...", "text": "A" } ] }
    ]
  }
}
```

Nunca inclui `correct` nem `explanation`. Erros: 404 (quiz de aula não pública, inclusive
arquivada), 409 `quiz_attempts_exhausted`, 409 `quiz_already_passed`.

### Resultado de uma tentativa (formato comum)

Usado pelo envio, pelo detalhe e pelo histórico. O backend decide o que entra com a **revisão
liberada** (R20: aprovado em alguma tentativa ou tentativas esgotadas), calculada a cada resposta.

Antes da liberação (`review_available: false`) — acerto/erro por pergunta, sem gabarito nem
explicação:

```json
{
  "data": {
    "id": "019a2c...",
    "attempt_number": 1,
    "status": "submitted",
    "quiz_revision": 2,
    "score_percent": 50.0,
    "passed": false,
    "correct_count": 2,
    "question_count": 4,
    "attempts_remaining": 2,
    "review_available": false,
    "questions": [
      { "id": "019a2b...", "prompt": "Qual tecla...", "selected_option_ids": ["019a2b..."], "is_correct": false }
    ]
  }
}
```

Depois da liberação (`review_available: true`) — cada pergunta ganha, **do snapshot daquela
tentativa**, as opções com `correct` e a `explanation`:

```json
{
  "id": "019a2b...",
  "prompt": "Qual tecla...",
  "selected_option_ids": ["019a2b..."],
  "is_correct": false,
  "options": [ { "id": "019a2b...", "text": "A", "correct": false }, { "id": "019a2b...", "text": "D", "correct": true } ],
  "explanation": "A tecla oposta (D) zera a velocidade em poucos ticks."
}
```

Tentativa aberta (`status: "in_progress"`): só as perguntas e opções, como no início.

### `POST /api/v1/academy/quiz-attempts/{attempt}/submission`

```json
{ "answers": [ { "question_id": "019a2b...", "option_ids": ["019a2b..."] } ] }
```

**200** com o resultado (formato comum). Se este envio aprovar ou esgotar as tentativas, a
própria resposta já vem com `review_available: true`. Erros: 404 (tentativa de outro usuário,
inexistente ou de aula não pública), 409 `quiz_attempt_already_submitted`, 422
`validation_failed` (pergunta faltando, opção fora do snapshot, mais de uma opção em
`single_choice`).

### `GET /api/v1/academy/quiz-attempts/{attempt}`

Detalhe de uma tentativa do próprio usuário, no formato comum. Tentativa de outro usuário: 404.
Funciona também para quiz de aula arquivada (histórico, R13a), com as mesmas regras de liberação.

### `GET /api/v1/me/quiz-attempts?quiz={key}`

Lista as tentativas do próprio usuário naquele quiz (resumo: número, data, revisão, nota,
aprovado, `review_available`), mais recentes primeiro. Detalhes por tentativa no endpoint
anterior.

## Códigos de erro novos

| Status | `code` | Quando |
|---|---|---|
| 409 | `quiz_attempts_exhausted` | sem tentativas restantes |
| 409 | `quiz_already_passed` | iniciar tentativa depois de aprovado |
| 409 | `quiz_attempt_already_submitted` | envio repetido |
