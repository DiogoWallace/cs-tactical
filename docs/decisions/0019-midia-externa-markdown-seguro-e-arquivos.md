# 0019 — Mídia externa, Markdown seguro e arquivos da Academy

- Status: Aceita (etapa 2.0). Ajusta a [ADR 0014](0014-headers-de-seguranca-e-csp.md) para as
  páginas de aula.
- Data: 2026-10-17

## Contexto

Aulas terão texto em Markdown, imagens e vídeos incorporados. A CSP atual aceita script inline por
causa do PPR do Next (ADR 0014), então qualquer HTML injetado no conteúdo seria executável. Produto
decidiu: vídeos só de provedores autorizados, Markdown sem HTML bruto nem scripts, imagens pela
abstração Storage do Laravel (disco local agora, S3 depois).

## Decisão

### Vídeos

1. Provedores autorizados na Fase 2: **YouTube**, sempre pelo domínio sem cookies
   (`https://www.youtube-nocookie.com/embed/{id}`). Outro provedor exige nova decisão.
2. O bloco `video` guarda **provedor + ID** validados (`[A-Za-z0-9_-]{11}`), nunca uma URL livre.
   O importador aceita a URL do YouTube no arquivo e a converte; qualquer outra URL é recusada.
3. O `iframe` tem `title` obrigatório (acessibilidade), `loading="lazy"`,
   `referrerpolicy="strict-origin-when-cross-origin"` e `sandbox` com o mínimo necessário.
4. **CSP por rota**: só as páginas de aula ganham `frame-src https://www.youtube-nocookie.com`.
   As demais páginas mantêm `frame-src 'none'`. Testes E2E verificam as duas situações.

### Markdown

5. Renderização **sem HTML bruto**: o renderizador não interpreta HTML (sem `rehype-raw`) e passa
   por sanitização com lista de elementos permitida. Imagens dentro do Markdown não são
   permitidas (usa-se o bloco `image`). Links externos abrem com `rel="noopener noreferrer"`;
   esquemas permitidos: `https:`, `mailto:` e caminhos internos.
6. **Defesa em profundidade**: o importador **recusa** Markdown que contenha HTML (tags ou
   comentários), em vez de confiar só no renderizador.
7. Títulos do Markdown começam em `h2` (o `h1` é o título da aula).

### Arquivos

8. Imagens pelo `Storage` do Laravel num disco dedicado (`academy`), local na Fase 2 e
   configurável para S3 sem mudar código. No banco ficam só caminho e metadados
   (modelo conceitual).
9. Aceita só `image/png`, `image/jpeg`, `image/webp` e `image/avif`, até 2 MB e 4096 px de lado,
   com o tipo conferido pelo conteúdo, não pela extensão. SVG não é aceito.
10. As imagens são servidas pela própria origem (`img-src 'self'` continua valendo). Na etapa
    2.2: `GET /api/v1/academy/media/{sha256}.{ext}` (passa pelo mesmo proxy que `/api`), só para
    imagem referenciada por conteúdo público ou para quem tem `manage-academy-content`
    ([academy-v1.md](../api/academy-v1.md)).

### Arquivos e transação do banco

O PostgreSQL desfaz a transação, mas não desfaz arquivos gravados no Storage. Para que uma
falha não deixe referência quebrada nem publicação parcial:

11. **Endereçamento por conteúdo**: cada imagem é gravada como `media/{sha256[0:2]}/{sha256}.{ext}`
    no disco `academy` (raiz `storage/app/academy`; caminho ajustado na implementação, etapa 2.1).
    O nome é o hash do conteúdo, então o arquivo é **imutável** e gravar o mesmo conteúdo de novo
    é um no-op (idempotente, deduplicado).
12. **Arquivos antes, banco depois**: a importação (a) valida tudo, (b) grava os arquivos que
    ainda não existem — num nome temporário, confere o hash e só então renomeia para o nome
    final (no S3, `put` do objeto completo, que é atômico) — e (c) abre a transação, que registra
    a mídia em `academy_media` e grava as referências. Uma referência só é gravada para um arquivo
    que já existe e foi conferido, logo **não há referência quebrada**.
13. **Falha depois dos arquivos**: a transação é desfeita, o banco fica como estava (nada
    publicado parcialmente) e os arquivos novos ficam **órfãos inofensivos**: imutáveis e sem
    referência.
14. **Limpeza de órfãos** (`academy:media-prune`, com `--dry-run`):
    - obtém o **mesmo *advisory lock* da importação** antes de começar; com uma importação em
      andamento, espera ou encerra sem remover nada (nunca roda em paralelo com ela);
    - considera só arquivos com mais de **24 horas** (retenção, pela data de modificação);
    - para **cada** arquivo, logo antes de remover, confere de novo que ele **não tem referência**:
      nenhuma linha em `academy_media` com aquele `sha256` nem com aquele caminho, nenhuma capa
      (`courses.cover_media_id`) e nenhum bloco `image` em `lessons.content` com o hash. Se houver
      qualquer referência, o arquivo fica e o caso vai para o relatório como anomalia;
    - remove um arquivo por vez e registra no log o caminho, o hash e o motivo.
    Nunca remove arquivo registrado ou referenciado.
15. **Sem sobrescrever nem apagar na importação**: trocar a imagem de uma aula grava outro hash;
    o arquivo antigo continua (pode estar no histórico ou em conteúdo arquivado). Remover mídia
    registrada é uma operação separada, fora da Fase 2.
16. **Concorrência**: importação e `media-prune` usam o mesmo *advisory lock* do PostgreSQL;
    uma segunda execução espera ou falha com mensagem clara, sem intercalar gravações e remoções.

## Alternativas consideradas

- **URL livre de vídeo**: abriria a CSP para qualquer domínio.
- **Confiar só no renderizador do Markdown**: um editor futuro ou um erro de configuração
  reabriria XSS.
- **SVG**: pode conter script; não compensa o risco.
- **Gravar arquivos dentro da transação e apagá-los no rollback**: um processo interrompido (falta
  de energia, `kill`) deixaria o mesmo órfão, sem a garantia do prune; e apagar na falha poderia
  remover um arquivo que outra versão já referencia.
- **Nome de arquivo pelo caminho original**: sobrescrever um arquivo em uso quebraria aulas já
  publicadas durante a importação.

## Consequências

- Mudar ou acrescentar provedor de vídeo é mudança de CSP, com teste.
- A reavaliação da CSP com nonce (ADR 0014) continua prevista antes de conteúdo gerado por
  jogadores.
