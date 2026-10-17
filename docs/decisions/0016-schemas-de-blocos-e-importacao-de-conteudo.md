# 0016 — Schemas versionados dos blocos e importação de conteúdo

- Status: Aceita (etapa 2.0). Complementa a [ADR 0008](0008-conteudo-estruturado-da-academy.md).
- Data: 2026-10-17

## Contexto

A ADR 0008 definiu que a aula é uma lista de blocos tipados em JSONB e que, no MVP, o conteúdo é
escrito em arquivos no repositório e importado por comando. Faltava definir como os blocos são
versionados e validados e como a importação se comporta diante de erros, renomeações e remoções.

## Decisão

### Blocos

1. `lessons.content` guarda `{"version": 1, "blocks": [...]}`; `lessons.content_version` repete a
   versão para consultas e migrações.
2. Cada tipo de bloco tem um schema **por versão**, definido no backend (classes de validação do
   módulo `Academy`) e espelhado no frontend (Zod). Tipos da versão 1: `markdown`, `callout`,
   `video`, `image`, `quiz_ref` ([conteúdo importável](../academy/conteudo-importavel.md)).
3. **Validação estrita**: tipo desconhecido, campo extra, campo obrigatório ausente ou limite
   excedido reprovam o bloco. Não há "melhor esforço".
4. Mudança compatível (campo opcional novo) mantém a versão. Mudança incompatível cria a versão
   seguinte, com migração explícita dos dados existentes; a aplicação lê só a versão atual.
5. `lessons.content_hash` (SHA-256 do conteúdo canônico) identifica mudanças de conteúdo
   (ADR 0017).

### Importação (`php artisan academy:import <pasta>`)

6. **Identidade estável**: cada curso, módulo, aula, quiz, pergunta e opção tem uma chave
   imutável (`key`) no arquivo. O `slug` pode mudar; a `key` não. A importação faz *upsert* pela
   chave.
7. **Transacional, em três fases**: (a) valida todos os arquivos, sem gravar nada; (b) grava as
   imagens novas no Storage, endereçadas pelo hash (imutáveis); (c) grava o banco numa única
   transação. Um erro de validação não grava nada; uma falha na fase (c) não deixa nada no banco,
   e os arquivos da fase (b) ficam órfãos inofensivos, limpos por `academy:media-prune`
   ([ADR 0019](0019-midia-externa-markdown-seguro-e-arquivos.md)). Um *advisory lock* impede
   duas importações ao mesmo tempo.
8. **Idempotente**: importar o mesmo conteúdo de novo não altera nada (nem `updated_at`).
9. **Sem exclusões implícitas**: um curso, módulo, aula ou quiz que sumiu dos arquivos **não** é
   apagado nem arquivado. O comando lista esses itens como "ausentes" e só os arquiva com
   `--archive-missing` (com confirmação), nunca os apaga. Perguntas e opções são parte do
   conteúdo do quiz e seguem a [ADR 0018](0018-quizzes-tentativas-e-historico.md): aposentadas se
   o quiz já teve tentativa, removidas só se nunca teve.
10. **Modo de simulação** (`--dry-run`): valida e mostra o que mudaria, sem gravar. Saída com
    resumo (criados, alterados, sem mudança, ausentes) e erros com arquivo e caminho do campo.
11. A importação roda só pelo terminal do servidor (sem endpoint HTTP na Fase 2).

## Alternativas consideradas

- **Upsert por slug**: renomear o slug criaria outro registro e deixaria o progresso no antigo.
- **IDs UUID escritos à mão nos arquivos**: estáveis, mas ilegíveis e fáceis de copiar errado.
- **Sincronização que apaga o que sumiu**: um arquivo movido por engano apagaria histórico.

## Consequências

- Autores precisam manter as chaves (convenção documentada; o importador recusa chave duplicada).
- O formato do arquivo é o mesmo que um editor visual futuro produzirá.
