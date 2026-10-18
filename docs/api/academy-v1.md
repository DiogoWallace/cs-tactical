# API da Academy e do Learning — contratos iniciais (v1)

> Definido na etapa 2.0 (aprovado em 2026-10-17). **Catálogo implementado na etapa 2.2**
> (`app/Modules/Academy/routes.php`); **Learning na etapa 2.4** (`app/Modules/Learning/routes.php`); quizzes ainda não. Convenções gerais
> (formato, erros, códigos) em [convencoes.md](convencoes.md). Regras e autorização em
> [academy.md](../architecture/academy.md).

## Visão geral

| Método | Caminho | Auth | Limite | Etapa |
|---|---|---|---|---|
| GET | `/api/v1/academy/courses` | não | `academy-public` | 2.2 ✔ |
| GET | `/api/v1/academy/courses/{course}` | não | `academy-public` | 2.2 ✔ |
| GET | `/api/v1/academy/courses/{course}/lessons/{lesson}` | não | `academy-public` | 2.2 ✔ |
| GET | `/api/v1/academy/media/{sha256}.{ext}` | não | `academy-media` | 2.2 ✔ |
| GET | `/api/v1/me/enrollments` | sim | `api` | 2.4 ✔ |
| PUT | `/api/v1/academy/courses/{course}/enrollment` | sim (+CSRF) | `learning-write` | 2.4 ✔ |
| GET | `/api/v1/academy/courses/{course}/progress` | sim | `api` | 2.4 ✔ |
| PUT | `/api/v1/academy/courses/{course}/lessons/{lesson}/completion` | sim (+CSRF) | `learning-write` | 2.4 ✔ |
| POST | `/api/v1/academy/quizzes/{quiz}/attempts` | sim (+CSRF) | `quiz-attempt` | 2.5 |
| POST | `/api/v1/academy/quiz-attempts/{attempt}/submission` | sim (+CSRF) | `quiz-attempt` | 2.5 |
| GET | `/api/v1/academy/quiz-attempts/{attempt}` | sim | `api` | 2.5 |
| GET | `/api/v1/me/quiz-attempts?quiz={key}` | sim | `api` | 2.5 |

`{course}` e `{lesson}` são **slugs**; `{quiz}` é a `key` do quiz; `{attempt}` é UUID. As rotas
públicas entram na lista explícita de `RouteAuthorizationTest`.

## Catálogo (público)

Regras comuns (etapa 2.2):

- **Modo editorial**: `include_unpublished=true` (ou `1`) **e** `manage-academy-content`
  (Gate, pela sessão do frontend quando houver). Nesse modo curso, módulos e aulas ganham o campo
  `status` e nada é filtrado por estado. Sem a capacidade, o parâmetro é ignorado; outros valores
  (`false`, `yes`...) também. Respostas sem `status` são iguais para todos.
- Slugs fora de `^[a-z0-9]+(-[a-z0-9]+)*$` respondem **404** no próprio roteador.
- Lista fechada de campos (Resources): nenhum `id`, `key`, `position`, datas internas, caminho de
  arquivo nem dado de quiz além do resumo de `quiz_ref`.
- Número de consultas fixo por endpoint (3, 4 e 6), coberto por testes.

### `GET /api/v1/academy/courses`

Query: `level` (`beginner`, `intermediate`, `advanced`, opcional), `page` (inteiro de 1 a
10 000). 20 por página, em `position`. Valor inválido: **422** `validation_failed`. `links` são
relativos à origem e mantêm `level` e `include_unpublished`; `meta` traz `current_page`,
`last_page`, `per_page` e `total`. `module_count`, `lesson_count` e `estimated_minutes` (soma das
aulas, `null` sem estimativa) contam só módulos e aulas públicos (no modo editorial, todos).

```json
{
  "data": [
    {
      "slug": "fundamentos-cs2",
      "title": "Fundamentos do CS2",
      "summary": "Movimentação, mira e economia para quem está começando.",
      "level": "beginner",
      "cover_image_url": "/api/v1/academy/media/3f9c...e1.webp",
      "module_count": 3,
      "lesson_count": 12,
      "estimated_minutes": 95
    }
  ],
  "links": { "first": "/api/v1/academy/courses?page=1", "last": "/api/v1/academy/courses?page=1", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
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

Não público: **404** `not_found`, igual ao de um slug inexistente. Módulos e aulas em `position`;
`has_quiz` = a aula tem quiz ativo (não aposentado). Sem dados de progresso (vêm de `/progress`,
para a resposta pública ser igual para todos e cacheável).

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
        { "type": "image", "data": { "url": "/api/v1/academy/media/ab12...9f.webp", "alt": "Gráfico de velocidade ao soltar e apertar a tecla oposta", "caption": null, "width": 1280, "height": 720 } },
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
dimensões (`alt` vazio = imagem decorativa); `quiz_ref` traz os dados resumidos do quiz ativo da
aula (`question_count` = perguntas não aposentadas), **sem perguntas nem gabarito**. Os blocos
saem com a lista fechada de campos do schema v1; bloco de tipo desconhecido, ou que cite imagem
sem registro ou outro quiz, é omitido e registrado no log (a importação impede esses casos).
`previous`/`next` seguem a ordem do curso (posição do módulo, depois da aula) e consideram só
aulas públicas; no modo editorial, todas.

Não pública (inclusive aula de outro curso ou arquivada, P1): **404**. Ler não grava nada (R10).

### `GET /api/v1/academy/media/{sha256}.{ext}`

Imagens do disco `academy` (ADR 0019), nas URLs que as respostas acima devolvem. O nome do
arquivo (`[0-9a-f]{64}` + `png|jpg|webp|avif`, validado na rota) só localiza o registro em
`academy_media`; o caminho lido é o do registro.

| Situação | Resposta |
|---|---|
| Capa de curso público ou bloco `image` de aula pública | **200** |
| Só em rascunho/arquivado ou sem referência, com `manage-academy-content` | **200** |
| Idem, sem a capacidade | **404** |
| Sem registro, extensão diferente da registrada, arquivo ausente ou com tamanho diferente do registro | **404** (os dois últimos com erro no log) |

**Sem cache**, pública ou editorial: `Cache-Control: no-store`, sem `ETag`, `Last-Modified` nem
`Expires`, e nunca **304** (`If-None-Match`/`If-Modified-Since` são ignorados). O acesso depende do
estado do conteúdo, que muda ao arquivar ou despublicar; com cache, um navegador, proxy ou CDN
poderia reaproveitar a imagem com base numa autorização anterior. Cada requisição é autorizada de
novo. As demais rotas do catálogo mantêm o padrão do Laravel (`no-cache, private`).

`Content-Type` é o `mime` do registro (conferido pelo conteúdo na importação), `Content-Disposition:
inline`. Headers de segurança (`nosniff`, CSP, `Cross-Origin-Resource-Policy: same-origin`) vêm do
Nginx da API. Não exige `include_unpublished` (o `<img>` não manda parâmetros extras).

## Learning (autenticado)

> Implementado na etapa 2.4 (`app/Modules/Learning`). Regras comuns:
>
> - O jogador é **sempre o da sessão**: nenhuma rota recebe id de usuário nem de matrícula, e um
>   `user_id` no corpo é ignorado (R13). Policies `EnrollmentPolicy`/`LessonProgressPolicy`
>   conferem a posse como segunda barreira; nenhum papel, nem `admin`, lê dados de outro jogador.
> - Visibilidade em toda leitura e escrita (R2): curso, módulo e aula publicados. Fora disso,
>   **404** sem revelar o motivo (rascunho, arquivado, aula de outro curso, inexistente).
> - Escritas travam as linhas do conteúdo com `FOR SHARE` na transação (ordem curso → módulo →
>   aula, a mesma da importação): nada é gravado em conteúdo que deixou de ser público no meio da
>   operação. Unicidade `(user_id, course_id)` e `(user_id, lesson_id)` com `ON CONFLICT`: duas
>   requisições simultâneas geram um registro só.
> - Repetir uma escrita não grava nada (idempotência verificada sem nenhum INSERT/UPDATE).
> - Lista fechada de campos: nenhum `id`, hash, `user_id` ou dado de quiz.
> - Número fixo de consultas: `/progress` 4, `/me/enrollments` 4, qualquer que seja o número de
>   aulas ou matrículas.

### `PUT /api/v1/academy/courses/{course}/enrollment`

Sem corpo. **201** na primeira matrícula, **200** se já existia (idempotente, sem escrita).

```json
{ "data": { "course": "fundamentos-cs2", "enrolled_at": "2026-11-02T13:05:00Z", "completed_at": null } }
```

Curso não público: 404 (também para `editor`/`admin`, mesmo com `include_unpublished`). Sem
sessão: 401. Token CSRF da sessão do frontend obrigatório (419 sem ele).

### `GET /api/v1/me/enrollments`

Lista as matrículas do usuário, atividade mais recente primeiro (`last_activity_at`), com
progresso resumido. Sem paginação: uma por curso, limitada pelo tamanho do catálogo.

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

Cursos arquivados aparecem com `status: "archived"` só como histórico (R13a):
`completed_lessons` conta as conclusões gravadas (inclusive de aulas arquivadas),
`total_lessons` e `percent` são `null` e não há `next_lesson`.

### `GET /api/v1/academy/courses/{course}/progress`

```json
{
  "data": {
    "enrolled": true,
    "enrolled_at": "2026-11-02T13:05:00Z",
    "completed_at": null,
    "percent": 41.67,
    "completed_lessons": 5,
    "total_lessons": 12,
    "new_lessons_available": false,
    "next_lesson": { "slug": "peek-seguro", "title": "Peek seguro" },
    "lessons": [
      { "slug": "counter-strafe", "status": "completed", "completed_at": "...", "updated_since_completion": false },
      { "slug": "peek-seguro", "status": "not_started", "completed_at": null, "updated_since_completion": false }
    ]
  }
}
```

- `lessons`: todas as aulas **públicas** do curso, na ordem do curso (posição do módulo, depois
  da aula). `status`: `not_started` ou `completed` (`in_progress` chega com os quizzes, 2.5).
- `percent` = concluídas ÷ públicas (R11), com duas casas; aulas arquivadas saem do cálculo.
- `next_lesson`: primeira aula pública não concluída (R10); `null` com tudo concluído.
- `completed_at`: gravado ao concluir a última aula pública, nunca apagado (R12);
  `new_lessons_available` = curso concluído que ganhou aulas depois (ADR 0017).
- `updated_since_completion` compara o `content_hash` atual com o da conclusão (ADR 0017).
- O resumo do quiz por aula (`quiz`: tentativas, melhor nota) entra na etapa 2.5.

Não matriculado: `enrolled: false`, números e `next_lesson` nulos, `new_lessons_available: false`
e lista vazia (200). Curso não público (rascunho ou arquivado): **404**; o histórico de cursos
arquivados fica em `/me/enrollments` e `/me/quiz-attempts` (R13a).

### `PUT /api/v1/academy/courses/{course}/lessons/{lesson}/completion`

Sem corpo. Conclui a aula (idempotente; auto-matrícula, R8): **200** com o progresso do curso
(igual a `/progress`). Concluir de novo mantém a primeira data e o hash daquele momento (R9) e
não grava nada; só uma conclusão nova atualiza `last_activity_at`. Aula não pública, de outro
curso ou inexistente: 404.

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
