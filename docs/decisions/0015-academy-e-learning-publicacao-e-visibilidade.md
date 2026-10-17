# 0015 — Módulos Academy e Learning, estados de publicação e visibilidade

- Status: Aceita (etapa 2.0)
- Data: 2026-10-17

## Contexto

A Fase 2 entrega um catálogo de cursos com aulas e quizzes e o progresso individual de cada
jogador. O catálogo é conteúdo editorial, igual para todos; o progresso é dado pessoal. O produto
decidiu que o catálogo e as aulas publicadas são públicos, que matrícula, progresso e quizzes
exigem login e que rascunhos só são vistos por quem tem `manage-academy-content` (ADR 0012).

## Decisão

1. **Dois módulos** no monólito modular (ADR 0001):
   - `Academy` (catálogo): cursos, módulos de curso, aulas, quizzes, perguntas e opções.
   - `Learning` (dados do jogador): matrículas, progresso por aula, tentativas de quiz.
   `Learning` depende de `Academy` (lê catálogo); `Academy` não conhece `Learning`. Teste de
   arquitetura impede o sentido inverso.
2. **Três estados** em cursos, módulos e aulas: `draft`, `published`, `archived` (string + check
   constraint). Quizzes não têm estado próprio: seguem a aula.
3. **Visibilidade efetiva**: um item é público só se ele e todos os ancestrais estão `published`.
   Aula publicada dentro de módulo em rascunho não aparece. A regra fica num único lugar (escopo
   de consulta do módulo `Academy`) e é coberta por testes em matriz (estado do curso × módulo ×
   aula).
4. **Rascunho e arquivado** só aparecem para quem tem `manage-academy-content`, e só quando a
   requisição pede explicitamente (`include_unpublished=true`). Para os demais, o recurso responde
   **404** (não revela que existe).
5. **Arquivar, não excluir**: conteúdo publicado nunca é apagado pela aplicação nem pelo
   importador. Arquivar remove do catálogo e das URLs públicas; o histórico de quem estudou fica
   intacto (ADR 0017). Exclusão física só de rascunhos que nunca foram publicados (por comando,
   numa etapa futura).
6. **Rotas públicas** de leitura do catálogo entram na lista pública explícita verificada por
   `RouteAuthorizationTest` (ADR 0012, item 5), com limite de requisições.

## Alternativas consideradas

- **Um módulo só**: misturaria regras editoriais e dados pessoais; a Fase 4 (coach vendo progresso
  atribuído) precisa da fronteira clara.
- **Visibilidade só pelo estado da aula**: publicaria por engano aulas de cursos ainda em revisão.
- **Exclusão com `ON DELETE CASCADE`**: apagaria histórico de jogadores ao reorganizar conteúdo.

## Consequências

- Toda consulta pública passa pelo escopo de visibilidade; consultas diretas aos models em
  controllers públicos são proibidas (revisão e teste de rota).
- Reorganizar conteúdo publicado é seguro, mas exige o fluxo de arquivamento em vez de remoção.
