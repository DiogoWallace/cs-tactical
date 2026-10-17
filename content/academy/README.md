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

O curso `fundamentos-cs2` é um exemplo. A aula de mira está em rascunho porque o vídeo é só um
exemplo e precisa ser trocado por um real antes de publicar.
