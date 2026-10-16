# Modelo conceitual de dados

> Proposta. **Nenhuma tabela de domínio é criada antes da fase que a utiliza.** Esta página é atualizada sempre que uma migration nova entra.

## Princípios

- Chave primária UUIDv7 (`uuid` nativo) em tabelas de domínio ([ADR 0005](../decisions/0005-uuidv7-como-chave-primaria.md)).
- Toda FK com `ON DELETE` explícito: `cascade` em dados dependentes, `restrict` onde há histórico.
- `team_id` em todo dado que pertence a uma equipe, sempre indexado.
- Unicidade garantida no banco (índices únicos), não só na aplicação.
- `created_at`/`updated_at` em todas as tabelas; `deleted_at` apenas onde recuperação faz sentido.
- Arquivos no storage; no banco, apenas caminho e metadados.
- Enums como `string` + check constraint (mais fácil de evoluir que o tipo `enum` do Postgres).

## Identidade (Fase 1) — implementado na etapa 1.1

Models em `app/Modules/Identity/Models`; migrations `2026_10_16_1000xx_*`.

- **users**: `id` (UUIDv7), `name` (até 120), `email` (até 254), `email_verified_at`, `password` (hash), `platform_role` (`user`, `editor`, `admin`; padrão `user`), `remember_token`, timestamps com fuso.
  - E-mail único **sem diferenciar maiúsculas**: índice único em `lower(email)`. O model grava o e-mail sem espaços e em minúsculas; buscas usam `User::whereEmailIs()` (índice comum em `email`).
  - Check constraints: `platform_role` nos três valores; `name` e `email` não vazios.
  - `platform_role` e `email_verified_at` não são preenchíveis em massa.
- **profiles** (1:1 com users): `id` (UUIDv7), `user_id` (único, FK com `ON DELETE CASCADE`), `nickname`, `country_code`, `timezone` (padrão `UTC`), `bio` (até 500), timestamps com fuso.
  - Apelido obrigatório, **único sem diferenciar maiúsculas** (índice em `lower(nickname)`), exibido como o jogador escreveu. Formato: 3 a 24 caracteres `[A-Za-z0-9_.-]` (check constraint). Buscas usam `Profile::whereNicknameIs()`.
  - `country_code`: ISO 3166-1 alfa-2 em maiúsculas (check constraint); `timezone`: identificador IANA, validado na aplicação.
  - `user_id` não é preenchível em massa.
  - Avatar e links externos ficam para quando forem implementados (arquivos no storage, ver princípios).
- **password_reset_tokens**: tabela técnica do broker do Laravel (`email` como chave, hash do token, `created_at`).

## Academy — catálogo global (Fase 2)

- **courses** 1:N **course_modules** 1:N **lessons**, cada nível com `slug`, `position` e `status` (`draft`, `published`).
- `courses.level`: `beginner`, `intermediate`, `advanced`. Trilhas começam como filtro por nível; uma tabela `tracks` só entra se a trilha precisar de mais que isso.
- **lessons.content**: JSONB com lista de blocos tipados + `content_version` ([ADR 0008](../decisions/0008-conteudo-estruturado-da-academy.md)).
- **quizzes** (de uma aula) 1:N **quiz_questions** 1:N **quiz_options**.

## Learning — dados do jogador (Fase 2)

- **enrollments**: user × course, único por par.
- **lesson_progress**: user × lesson, `status`, `completed_at`, único por par.
- **quiz_attempts** 1:N **quiz_attempt_answers**.

O progresso é **do jogador**. Nenhuma equipe lê essas tabelas diretamente; o acesso passa pelas atribuições (abaixo).

## Teams (Fase 3)

- **teams**: nome, slug, tag, `created_by`.
- **team_members**: team × user (único por par), com:
  - `role`: papel na equipe, **afeta permissões** (`owner`, `manager`, `coach`, `analyst`, `player`);
  - `game_role`: função no CS2, **não afeta permissões** (`igl`, `awper`, `entry`, `support`, `lurker`, nulo).
  
  Ver [ADR 0007](../decisions/0007-papeis-de-equipe-e-funcoes-no-jogo.md). Um usuário pode estar em várias equipes.
- **team_invitations**: e-mail convidado, papel proposto, hash do token, `expires_at`, `accepted_at`.

## Estratégias e mapas (Fase 3, com `maps` antes se a Academy precisar)

- **maps**: referência via seed (nome, slug, `active_duty`).
- **strategies**: `map_id`, `team_id` (nulo = conteúdo público), `author_id`, `visibility` (`private`, `team`, `public`), `side` (T/CT), tipo.
- **strategy_versions**: histórico do conteúdo (JSONB); `strategies.current_version_id` aponta para a versão vigente.
- **playbooks** (de uma equipe) N:N **strategies** via `playbook_strategy` com `position`.
- **utility_lineups**: `map_id`, tipo (smoke, flash, molotov, HE), lado, posição de arremesso, alvo, técnica, mídia.

## Training — ponte Teams ↔ Academy (Fase 4)

- **training_sessions**: agenda da equipe.
- **training_assignments**: team × membro × item da Academy (curso ou aula), `assigned_by`, prazo, observação.
  - O progresso **não é copiado**: é lido de `lesson_progress`/`enrollments`.
  - A Policy permite ao coach ver o progresso apenas dos itens atribuídos por ele/pela equipe.

## Auditoria (Fase 4)

- **activity_log**: ator, ação, sujeito (tipo + id), `team_id`, metadados JSONB, data. Alimentada por eventos de domínio.

## Diagrama de relacionamentos (resumo)

```
users 1─1 profiles
users 1─N team_members N─1 teams 1─N team_invitations
users 1─N enrollments N─1 courses 1─N course_modules 1─N lessons 1─0..1 quizzes
users 1─N lesson_progress N─1 lessons
teams 1─N training_assignments N─1 team_members
training_assignments N─1 (courses | lessons)
teams 0..1─N strategies N─1 maps ;  strategies 1─N strategy_versions
teams 1─N playbooks N─N strategies
maps 1─N utility_lineups
```
