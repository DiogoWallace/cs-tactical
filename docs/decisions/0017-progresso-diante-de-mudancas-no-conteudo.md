# 0017 — Progresso do jogador diante de mudanças no conteúdo

- Status: Aceita (etapa 2.0)
- Data: 2026-10-17

## Contexto

O conteúdo da Academy muda depois de publicado: aulas são corrigidas, reordenadas, movidas entre
módulos, acrescentadas ou arquivadas. O progresso do jogador não pode se perder nem ficar
incoerente com essas mudanças.

## Decisão

1. **O progresso pertence à aula** (`lesson_progress` por usuário × aula), não à posição dela.
   Reordenar aulas ou movê-las entre módulos **do mesmo curso** preserva o progresso. Mover uma
   aula para outro curso é recusado pelo importador (cria-se uma aula nova).
2. **Conclusão é histórica**: uma aula concluída continua concluída depois de editada. O registro
   guarda o `content_hash` da conclusão; se o hash atual for outro, a interface indica "atualizada
   desde que você concluiu", sem desfazer a conclusão.
3. **Percentual do curso** = aulas publicadas concluídas ÷ aulas publicadas visíveis. Aulas
   arquivadas saem do denominador; a conclusão delas continua no histórico.
4. **Curso concluído**: `enrollments.completed_at` é gravado quando todas as aulas publicadas
   estão concluídas e **não é apagado** se aulas novas forem publicadas depois; a interface mostra
   "novas aulas disponíveis".
5. **Conteúdo arquivado não é lido por ninguém sem `manage-academy-content`**, nem por quem
   estava matriculado (decisão P1): sai do catálogo e as URLs respondem 404. O jogador mantém o
   próprio histórico (títulos, datas de conclusão, tentativas e notas) em "Meu progresso", e
   conclusões de curso já conquistadas (`enrollments.completed_at`) continuam valendo. Não há
   progresso novo em conteúdo arquivado: abrir, concluir aula ou enviar tentativa respondem 404.
6. **Integridade**: progresso referencia conteúdo com `ON DELETE RESTRICT` (o banco impede apagar
   conteúdo com histórico); dados do jogador referenciam `users` com `ON DELETE CASCADE` (excluir
   a conta apaga o histórico pessoal).
7. **Quizzes**: ver [ADR 0018](0018-quizzes-tentativas-e-historico.md).

## Alternativas consideradas

- **Invalidar a conclusão ao editar a aula**: puniria o jogador por uma correção de digitação.
- **Progresso por posição** (módulo/ordem): quebraria a cada reorganização.
- **Recalcular "curso concluído" sempre**: um curso que ganha uma aula "desconcluiria" quem já
  terminou.

## Consequências

- Consultas de progresso sempre juntam com o escopo de visibilidade (ADR 0015).
- Relatórios futuros (Fase 4, coach) leem o mesmo histórico, sem cópias.
