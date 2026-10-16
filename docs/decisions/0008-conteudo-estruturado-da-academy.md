# 0008 — Conteúdo da Academy em blocos estruturados

- Status: Aceita (implementação na Fase 2)
- Data: 2026-10-16

## Contexto

As aulas da Academy terão mais do que texto: vídeos, lineups de utilitárias, posições em mapas, quizzes e, no futuro, simulações de decisão. Guardar a aula como um único Markdown impediria renderizar esses elementos de forma interativa e consultar o conteúdo (ex.: "quais aulas usam este lineup").

## Decisão

- O conteúdo de uma aula é uma **lista ordenada de blocos tipados**, armazenada em `lessons.content` (JSONB) com `content_version`.
- Cada bloco tem `type` e `data`. Tipos iniciais previstos: `markdown`, `callout`, `video` (URL externa), `image` (arquivo no storage), `lineup_ref` (referência a `utility_lineups`), `quiz_ref`. Novos tipos são adicionados sem migration.
- Cada tipo de bloco tem um schema: validado no backend (Form Request) e espelhado no frontend com Zod; cada tipo tem um componente React próprio.
- Blocos que referenciam entidades (lineup, quiz) guardam só o ID; a API resolve as referências.
- Markdown continua existindo como um tipo de bloco, para texto corrido.
- No MVP, o conteúdo pode ser escrito em arquivos no repositório (YAML/JSON com blocos) e importado por um comando artisan; um editor visual pode vir depois, gerando o mesmo formato.

## Alternativas consideradas

- **Markdown/MDX puro**: simples para texto, mas componentes interativos ficariam presos a sintaxe embutida, difícil de validar e consultar.
- **Tabela por tipo de bloco**: normalização excessiva para conteúdo editorial que é lido sempre por inteiro.

## Consequências

- O mesmo formato serve para o importador do MVP e para um editor futuro.
- Mudanças incompatíveis num tipo de bloco exigem migração dos dados JSON (controlada por `content_version`).
