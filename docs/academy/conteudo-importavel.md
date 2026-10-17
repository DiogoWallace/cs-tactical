# Conteúdo importável da Academy e schemas dos blocos

> Definido na etapa 2.0 (aprovado em 2026-10-17). Decisões: [ADR 0008](../decisions/0008-conteudo-estruturado-da-academy.md),
> [ADR 0016](../decisions/0016-schemas-de-blocos-e-importacao-de-conteudo.md) e
> [ADR 0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md).

## Estrutura dos arquivos

O conteúdo fica no repositório, fora das aplicações, e é importado de dentro do container `api`,
onde `./content` é montado (só leitura) em `/var/www/content` e `ACADEMY_CONTENT_PATH` aponta para
`/var/www/content/academy`:

```bash
docker compose exec api php artisan academy:import --dry-run   # valida e mostra o que mudaria
docker compose exec api php artisan academy:import             # importa
```

| Opção | Efeito |
|---|---|
| `{path}` | outra pasta (padrão: `config('academy.content_path')`) |
| `--dry-run` | valida tudo, inclusive contra o banco, e desfaz a transação; não grava imagens |
| `--archive-missing` | arquiva cursos, módulos e aulas ausentes dos arquivos; lista e pede confirmação |
| `--force` | dispensa a confirmação do `--archive-missing` |

```
content/academy/
├── fundamentos-cs2/                 # um diretório por curso (nome livre; a identidade é a `key`)
│   ├── course.yaml
│   ├── media/                       # imagens usadas pelo curso
│   │   ├── capa.webp
│   │   └── counter-strafe-grafico.webp
│   ├── 01-movimentacao/             # um diretório por módulo; o prefixo numérico é só organização
│   │   ├── module.yaml
│   │   ├── 01-counter-strafe.yaml   # uma aula por arquivo (blocos + quiz opcional)
│   │   └── 02-peek-seguro.yaml
│   └── 02-mira/
│       ├── module.yaml
│       └── 01-posicionamento-da-mira.yaml
└── economia/
    └── ...
```

- A **ordem** vem de `position` nos arquivos, não do nome do diretório.
- Caminhos de imagem são relativos ao diretório `media/` do curso; nome só com minúsculas,
  números, `.`, `_` e `-`, sem subpastas.
- Arquivos em UTF-8, YAML 1.2, até 1 MB. Chaves desconhecidas em qualquer nível reprovam a
  importação; chave repetida, tags personalizadas e objetos PHP (`!php/...`) também.
- Entradas que começam com `_` ou `.` são ignoradas (rascunhos locais e `_fixtures/`, os casos
  de teste dos blocos compartilhados com o frontend); arquivos `.md` também (anotações). Outros
  arquivos fora do lugar e links simbólicos reprovam.

### Identidade, status e itens ausentes

- A identidade é a `key`: curso (global), módulo (por curso), aula (por curso) e quiz (global).
  Mudar o `slug`, a posição ou o módulo de uma aula (no mesmo curso) atualiza o mesmo registro;
  slugs e posições podem ser trocados entre itens numa mesma importação.
- **Mover aula entre cursos é recusado**: aula nova num curso cuja `key` existe em outro curso
  e sumiu dos arquivos de lá. Crie com outra `key` e arquive a antiga. A mesma `key` em dois
  cursos, ambas nos arquivos, é permitida (são aulas diferentes).
- Status: `draft` → `published` → `archived` → `published`. **Publicado não volta a rascunho**
  (só pode ser arquivado). Curso publicado precisa de um módulo publicado com uma aula publicada.
- Itens que sumiram dos arquivos **nunca são apagados**: aparecem como "ausentes" e continuam
  como estão, inclusive com o slug e a posição deles (usar esse slug ou posição em outro item
  reprova, com mensagem). Só são arquivados com `--archive-missing`.

## Formatos

### `course.yaml`

```yaml
key: fundamentos-cs2            # imutável
slug: fundamentos-cs2
title: Fundamentos do CS2
summary: Movimentação, mira e economia para quem está começando.
level: beginner                 # beginner | intermediate | advanced
status: published               # draft | published | archived
position: 1
cover_image: capa.webp          # opcional, em media/; vira courses.cover_media_id
```

### `module.yaml`

```yaml
key: movimentacao
slug: movimentacao
title: Movimentação
summary: Como se mover sem perder precisão.   # opcional
status: published
position: 1
```

### Aula (`01-counter-strafe.yaml`)

```yaml
key: counter-strafe
slug: counter-strafe
title: Counter-strafe
summary: Parar instantaneamente para atirar com precisão.   # opcional
status: published
position: 1
estimated_minutes: 8                                        # opcional, 1..600
content:
  version: 1
  blocks:
    - type: markdown
      text: |
        ## Por que parar antes de atirar
        No CS2, a precisão cai enquanto você se move...
    - type: callout
      variant: tip
      title: Treine no modo offline
      text: Pratique soltando **A** e apertando **D** por um instante.
    - type: video
      url: https://www.youtube.com/watch?v=AbCdEfGhIjK      # convertido em provider + video_id
      title: Demonstração do counter-strafe
      start_seconds: 12                                    # opcional
    - type: image
      src: counter-strafe-grafico.webp
      alt: Gráfico da velocidade caindo a zero ao apertar a tecla oposta
      caption: Velocidade ao longo do tempo.               # opcional
    - type: quiz_ref
      quiz: counter-strafe-quiz
quiz:                                                      # opcional (0..1 por aula)
  key: counter-strafe-quiz
  title: Teste rápido
  max_attempts: 3                                          # opcional, padrão 3
  pass_percent: 70                                         # opcional, padrão 70
  questions:
    - key: tecla-oposta
      kind: single_choice
      prompt: Indo para a esquerda (A), qual tecla para o personagem mais rápido?
      explanation: A tecla oposta (D) zera a velocidade em poucos ticks.   # opcional
      options:
        - { key: a, text: "A", correct: false }
        - { key: d, text: "D", correct: true }
        - { key: s, text: "S", correct: false }
    - key: quando-atirar
      kind: multiple_choice
      prompt: Quais situações pedem counter-strafe antes do tiro?
      options:
        - { key: peek, text: "Ao dar peek num ângulo", correct: true }
        - { key: duelo, text: "Num duelo a média distância", correct: true }
        - { key: correndo, text: "Correndo pelo mapa sem inimigo à vista", correct: false }
```

Uma pergunta ou opção retirada do arquivo é **aposentada** (`retired_at`) se o quiz já tiver
**qualquer** tentativa (aberta ou enviada), porque tentativas abertas guardam a pergunta no
snapshot e vão respondê-la; só é removida se o quiz nunca teve tentativa (ADR 0018). O mesmo vale
para o quiz inteiro quando ele sai da aula ou troca de `key`: com tentativa, é aposentado e a aula
recebe o novo. Item aposentado que volta ao arquivo é reativado. Itens de curso, módulo e aula
que sumirem só são listados como "ausentes" (ADR 0016).

A `revision` do quiz sobe quando muda o que afeta a correção: `max_attempts`, `pass_percent`,
perguntas, opções, gabarito, explicações ou ordem. Mudar só o título não cria revisão.

## Schemas dos blocos (versão 1)

Regras comuns: `type` obrigatório; campos extras proibidos; no máximo **100 blocos** por aula;
conteúdo canônico (JSON ordenado) até **256 KB**.

| Tipo | Campos (arquivo → banco) | Validação |
|---|---|---|
| `markdown` | `text` | 1..20 000 caracteres; **sem HTML**: `<` seguido de letra, `/`, `!` ou `?` reprova (tags, comentários, autolinks), inclusive em blocos de código; para "menor que", use `<` seguido de espaço; sem imagens Markdown (`![...]`); links só `https:`, `mailto:`, `#âncora` ou caminhos internos `/...` (não `//...` nem `/\...`); títulos a partir de `##` |
| `callout` | `variant`, `title`?, `text` | `variant`: `info`, `tip`, `warning`; `title` até 120; `text` 1..2000, Markdown **em linha** (negrito, itálico, código, links) com as mesmas restrições |
| `video` | `url` → `provider`, `video_id`; `title`; `start_seconds`? | `url` de `youtube.com/watch`, `youtu.be` ou `youtube.com/embed`; ID `[A-Za-z0-9_-]{11}`; `title` obrigatório (1..160); `start_seconds` 0..86 400 |
| `image` | `src` → `media` (sha256 do arquivo, registrado em `academy_media` com tipo e dimensões); `alt`; `caption`? | arquivo em `media/`; PNG, JPEG, WebP ou AVIF (tipo conferido pelo conteúdo); até 2 MB; até 4096 px de lado; `alt` obrigatório (1..300), exceto com `decorative: true` (então `alt` vazio); `caption` até 300 |
| `quiz_ref` | `quiz` | referencia a `key` do quiz **desta aula**; no máximo um por aula |

O mesmo schema existe em PHP (validação do importador e da API) e em Zod (renderização no
frontend); um teste compara os dois a partir de fixtures compartilhadas.

## Quizzes

| Campo | Regras |
|---|---|
| `key` | único global |
| `title` | 1..160 |
| `max_attempts` | 1..20, padrão 3 |
| `pass_percent` | 1..100, padrão 70 |
| `questions` | 1..30 perguntas |
| `kind` | `single_choice` (exatamente 1 correta) ou `multiple_choice` (ao menos 1 correta) |
| `prompt` / `explanation` | 1..1000 / até 2000, Markdown em linha |
| `options` | 2..8 por pergunta; `text` 1..500, Markdown em linha; `key` único na pergunta; `correct` obrigatório |

## Imagens e falhas

As imagens seguem a [ADR 0019](../decisions/0019-midia-externa-markdown-seguro-e-arquivos.md):
gravadas antes da transação, com nome igual ao hash do conteúdo (imutáveis), e registradas em
`academy_media` dentro da transação. Uma importação que falhe depois de gravar arquivos não deixa
nada no banco; os arquivos sem registro são removidos por `php artisan academy:media-prune`,
que nunca roda junto com uma importação (mesmo lock), só considera arquivos com mais de 24 horas
e reconfere, arquivo por arquivo, que não há referência antes de remover (`--dry-run`
disponível).

## Saída do importador

```
academy:import /var/www/content/academy --dry-run
  cursos   criados 1 · alterados 0 · sem mudança 0
  módulos  criados 2 · alterados 0 · sem mudança 0
  aulas    criadas 3 · alteradas 0 · sem mudança 0
  quizzes  criados 1 · alterados 0 · sem mudança 0
  quizzes  revisão +0 · itens aposentados 0 · itens removidos 0
  mídias   registradas 2 · arquivos gravados 0
  ausentes (não serão alterados): nenhum
  arquivados: nenhum
  erros: 0
Simulação: nada foi gravado.
```

Sem mudança nos arquivos, a importação não escreve nada (nem no banco nem no disco) e termina
com "Importação concluída: nada mudou.".

Erros indicam arquivo e caminho do campo e cancelam tudo, por exemplo
`fundamentos-cs2/01-movimentacao/01-counter-strafe.yaml: content.blocks[3].alt: obrigatório`.

Falha depois da validação (ex.: erro do banco) desfaz a transação inteira; as imagens já gravadas
ficam sem registro e o comando avisa que `academy:media-prune` as remove depois de 24 horas.
Importação e limpeza usam o mesmo *advisory lock*: com outra em andamento, o comando sai na hora
com erro, sem gravar nada.
