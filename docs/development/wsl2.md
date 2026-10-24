# Ambiente de desenvolvimento no Windows com WSL2

## Pré-requisitos (uma vez)

1. **WSL2 com Ubuntu**: `wsl --install -d Ubuntu` no PowerShell (administrador).
2. **Docker Desktop** com:
   - *Settings → General*: "Use the WSL 2 based engine" ativado;
   - *Settings → Resources → WSL Integration*: integração ativada para a distribuição Ubuntu.
3. **VS Code** com a extensão *WSL* (`ms-vscode-remote.remote-wsl`).
4. **Git dentro do Ubuntu** (já vem instalado) com:

   ```bash
   git config --global core.autocrlf false
   git config --global init.defaultBranch main
   ```

Não é preciso instalar PHP, Composer, Node.js ou PostgreSQL no Windows nem no Ubuntu: tudo roda em containers.

## Onde ficam os arquivos

Clone o repositório **dentro do sistema de arquivos Linux**, nunca em `/mnt/c`:

```bash
mkdir -p ~/projects && cd ~/projects
git clone <url-do-repositorio> cs-tactical
cd cs-tactical
code .
```

Motivos:

- arquivos em `/mnt/c` passam por uma camada de tradução (9P) muito mais lenta;
- eventos de alteração de arquivo (inotify) não atravessam essa camada, então o hot reload do Next.js e do Laravel não funciona;
- permissões Unix não são preservadas.

`make doctor` avisa se o repositório estiver em `/mnt`.

## Permissões de arquivos

Os containers criam arquivos dentro do repositório (`vendor/`, `node_modules/`, `storage/logs`). Para que pertençam ao seu usuário do Ubuntu e não ao `root`:

- `make setup` grava `HOST_UID` e `HOST_GID` no `.env` (normalmente 1000/1000);
- as imagens da API e do frontend são construídas com esse UID/GID e os processos rodam como esse usuário.

Se algum arquivo aparecer como `root`, rode `make setup`, depois `make build` e `make up`.

## Volumes

| Dado | Tipo | Observação |
|---|---|---|
| Código das aplicações | bind mount (`apps/api`, `apps/web`) | editado no VS Code, refletido na hora nos containers |
| `vendor/`, `node_modules/` | dentro do bind mount | instalados pelo container, visíveis ao VS Code (autocomplete e lint funcionam sem PHP/Node no host) |
| `.next/` | dentro do bind mount | cache de build do Next, ignorado pelo git |
| store do pnpm, cache do Corepack | volumes nomeados `pnpm-store`, `corepack-cache` | instalações seguintes ficam mais rápidas |
| PostgreSQL | volume nomeado `cs-tactical_postgres-data` | sobrevive a `make down`; apagado só com `make destroy` |
| Redis | volume nomeado `cs-tactical_redis-data` | AOF ligado, filas sobrevivem a reinícios |
| Imagens da Academy | `apps/api/storage/app/academy` (dentro do bind mount) | ficam no disco do WSL, fora dos volumes do Docker |

### Backup e restauração do banco de desenvolvimento

Os volumes do Docker somem se o Docker Desktop for reinstalado ou limpo. Antes disso (ou antes de
migrations arriscadas), gere um dump fora do Docker:

```bash
mkdir -p ~/backups
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner' > ~/backups/cs_tactical-$(date +%Y%m%d-%H%M%S).dump
```

Para restaurar num banco **vazio** (por exemplo, depois de `make up` com volumes novos, antes de
qualquer `make migrate`):

```bash
docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error --single-transaction' < ~/backups/<arquivo>.dump
make artisan c="migrate:status"   # todas como Ran, nenhuma Pending
```

As sessões ficam no Redis: depois de uma restauração com volumes novos, é preciso entrar de novo.
O banco de testes (`cs_tactical_test`) não precisa de backup; os testes o recriam.

## Hot reload

Com o código no ext4 do WSL, os eventos de arquivo chegam aos containers e não é preciso *polling*. Se o hot reload do Next parar de funcionar (ex.: repositório num caminho incomum), defina `WATCHPACK_POLLING=true` em `apps/web/.env` como paliativo.

## Portas

Todas as portas são publicadas apenas em `127.0.0.1` e configuráveis no `.env`:

| Serviço | Variável | Padrão |
|---|---|---|
| Frontend | `WEB_PORT` | 3000 |
| API | `API_PORT` | 8000 |
| Mailpit (interface web) | `MAILPIT_UI_PORT` | 8025 |
| PostgreSQL | — | não publicado |
| Redis | — | não publicado |

Para acessar PostgreSQL ou Redis com uma ferramenta gráfica:

```bash
cp compose.override.example.yaml compose.override.yaml
make up
# PostgreSQL em 127.0.0.1:54320, Redis em 127.0.0.1:63790
```

O Docker Desktop encaminha essas portas para o Windows, então o navegador e ferramentas do Windows acessam `localhost:<porta>` normalmente.

## Limitar memória do WSL (opcional)

Crie `C:\Users\<voce>\.wslconfig`:

```ini
[wsl2]
memory=8GB
processors=4
```

E rode `wsl --shutdown` no PowerShell para aplicar.

## Problemas comuns

| Sintoma | Causa provável | Solução |
|---|---|---|
| `Cannot connect to the Docker daemon` | Docker Desktop fechado ou sem integração WSL | Abra o Docker Desktop e ative a integração para o Ubuntu |
| `docker: command not found` no Ubuntu com a integração ativada, `/mnt/wsl/docker-desktop/cli-tools` vazio; o Docker Desktop fecha com "rename ... `sailor-ingest.sock` ... `.sock.stale`: Não é possível o acesso ao arquivo" | Sockets travados em `%LOCALAPPDATA%\Docker\run` depois de reinstalar ou limpar o Docker Desktop (o Windows não os apaga) | Feche o Docker Desktop, **renomeie** (não apague) `%LOCALAPPDATA%\Docker\run` e `%LOCALAPPDATA%\docker-secrets-engine` (ex.: sufixo `.old-<data>`) e abra de novo; a integração volta em cerca de 1 minuto. Não use "Reset to factory defaults" |
| `port is already allocated` | Outro projeto usa a porta | Altere a porta no `.env` e rode `make up` |
| Arquivos com dono `root` | `HOST_UID` incorreto | `make setup && make build && make up` |
| Hot reload não funciona | Repositório em `/mnt/c` | Mova para `~/projects` |
| Console do navegador: `WebSocket connection to 'ws://.../_next/hmr' failed` | Endereço não liberado no dev server do Next | Use `localhost:3000` ou `127.0.0.1:3000` (ambos liberados em `allowedDevOrigins`); outro host precisa ser adicionado em `apps/web/next.config.ts` |
| Scripts falham com `\r: command not found` | Arquivo com CRLF | `git config --global core.autocrlf false` e clone novamente |
| `Pool overlaps with other one on this address space` | A faixa da rede interna (`172.31.250.0/24`) já é usada por outra rede | Altere `INTERNAL_SUBNET`, `INTERNAL_IP_RANGE` e `WEB_INTERNAL_IP` juntos no `.env` da raiz ([ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md)) e rode `make down && make up` |
| `network cs-tactical-internal ... has incorrect config` ou o web sem o IP fixo | Rede criada antes da faixa fixa (Fase 0) | `make down && make up` recria a rede (os volumes de dados são mantidos) |
| Depois de `pnpm add`/`remove`: `Could not find the Next.js package`, `Invalid hook call` ou avisos `Could not validate instant` em todas as páginas | O `next dev` continuou rodando enquanto o pnpm movia o pacote `next` (um pacote novo que é peer dependency do Next muda o caminho dele no `node_modules/.pnpm`); o servidor fica com duas cópias do Next/React. Ocorrência registrada na auditoria da Fase 1 | `docker compose restart web` depois de qualquer mudança de dependências do frontend |
