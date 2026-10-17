# Conteúdo da Academy

Cursos importados por `php artisan academy:import` (dentro do container `api`, onde esta pasta é
montada em `/var/www/content`). Formato, schemas e regras: [docs/academy/conteudo-importavel.md](../../docs/academy/conteudo-importavel.md).

```bash
docker compose exec api php artisan academy:import --dry-run   # valida e mostra o que mudaria
docker compose exec api php artisan academy:import             # importa
```

- A identidade de cada item é a `key` (imutável); mudar o `slug` não cria outro registro.
- Itens apagados daqui **não** são apagados do banco: aparecem como "ausentes". Para tirá-los
  do ar, use `status: archived` ou `--archive-missing` (pede confirmação).
- Pastas e arquivos que começam com `_` ou `.` são ignorados (rascunhos locais).
- Imagens: PNG, JPEG, WebP ou AVIF, até 2 MB e 4096 px, na pasta `media/` do curso.

## Pasta vazia dentro do container

O Docker monta a pasta `content/` pelo diretório, não pelo caminho. Se ela for apagada e criada de
novo com os containers rodando (por exemplo, um `git switch` para um commit sem `content/` e a
volta), `api` e `worker` continuam presos ao diretório antigo e enxergam `/var/www/content`
vazio. O importador acusa `pasta de conteúdo não encontrada`. Edições de arquivos dentro da
pasta não causam isso.

Para corrigir no ambiente de desenvolvimento, recrie só esses dois containers:

```bash
docker compose up -d --no-deps --force-recreate api worker
docker compose exec api ls /var/www/content/academy   # deve listar os cursos
```

`--no-deps` impede que o PostgreSQL e o Redis sejam recriados, e recriar um container não apaga
volumes: os dados de `postgres-data` e `redis-data` continuam intactos. Nunca use
`docker compose down -v` para isso.

O curso `fundamentos-cs2` é um exemplo. A aula de mira está em rascunho porque o vídeo é só um
exemplo e precisa ser trocado por um real antes de publicar.
