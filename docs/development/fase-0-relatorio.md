# Relatório da Fase 0 — Fundação técnica

Registro de cada etapa: arquivos, decisões, comandos, testes e pendências. Atualizado ao fim de cada etapa.

---

## Etapa 0.1 — Esqueleto do repositório e documentação

**Commit:** `chore: add repository skeleton, architecture docs and ADRs`

### Arquivos criados

| Arquivo | Conteúdo |
|---|---|
| `README.md` | Visão do projeto, início rápido, links da documentação |
| `.editorconfig`, `.gitattributes` | UTF-8, LF forçado em todo texto (evita CRLF vindo do Windows), indentação 2/4 |
| `.gitignore` | `.env*` (exceto exemplos), `compose.override.yaml`, arquivos de editor/SO |
| `.vscode/extensions.json` | Extensões recomendadas (WSL, Docker, PHP, Pint, ESLint, Prettier, Tailwind) |
| `docs/architecture/visao-geral.md` | Componentes, regras do monólito modular, módulos por fase, estrutura do frontend |
| `docs/architecture/autenticacao.md` | Fluxo completo de sessão, CSRF, cookies, Server Components e plano de validação (ajuste 1) |
| `docs/database/modelo-conceitual.md` | Entidades por fase, com papéis de equipe separados da função no jogo (ajuste 4) |
| `docs/api/convencoes.md` | Formato de URLs, respostas, erros e endpoints atuais |
| `docs/decisions/0000` a `0008` | Template e 8 ADRs com as decisões aprovadas |

### Decisões técnicas

- **Documentação em português, código em inglês.** As ADRs também ficaram em português, para facilitar a leitura (a análise inicial previa ADRs em inglês).
- **ADR 0007 — papéis**: três dimensões independentes. `users.platform_role` e `team_members.role` afetam autorização; `team_members.game_role` (IGL, AWPer...) nunca é verificado em Policies.
- **ADR 0008 — conteúdo estruturado (ajuste 3)**: aulas como lista de blocos tipados em JSONB (`markdown`, `video`, `image`, `lineup_ref`, `quiz_ref`...), cada tipo com schema validado no backend e no frontend. Markdown vira só um dos tipos de bloco.
- **Regra de autenticação para Server Components**: eles só leem; escritas acontecem no navegador pelo proxy, porque `Set-Cookie` vindo de chamadas feitas no servidor não chega ao navegador.

### Testes

Não se aplica (só documentação).

---

## Etapa 0.2 — Infraestrutura Docker: PostgreSQL, Redis, Mailpit

**Commit:** `build(infra): add compose services for postgres, redis and mailpit`

### Arquivos criados

| Arquivo | Conteúdo |
|---|---|
| `compose.yaml` | Serviços `postgres` (18), `redis` (8, AOF), `mailpit` (v1.27), rede `internal`, volumes nomeados, healthchecks |
| `compose.override.example.yaml` | Exemplo opcional para expor Postgres (54320) e Redis (63790) no host |
| `.env.example` | `HOST_UID/GID`, portas (`WEB_PORT`, `API_PORT`, `MAILPIT_UI_PORT`), credenciais de dev do Postgres |
| `infra/docker/postgres/init/01-create-test-database.sh` | Cria `cs_tactical_test` na primeira inicialização do volume |
| `scripts/setup.sh` | Cria `.env` sem sobrescrever o existente e grava UID/GID do usuário |
| `scripts/doctor.sh` | Verifica WSL, localização fora de `/mnt`, Docker, Compose, `.env`, UID, `autocrlf` e portas livres |
| `Makefile` | `help`, `setup`, `doctor`, `up`, `down`, `restart`, `ps`, `logs`, `build`, `psql`, `redis-cli`, `destroy` |
| `docs/development/wsl2.md` | Pré-requisitos, localização do código, permissões, volumes, hot reload, portas, problemas comuns (ajuste 2) |

### Decisões técnicas

- **Postgres e Redis não publicados no host** por padrão; só a rede interna. O override de exemplo usa portas fora do padrão para não conflitar com instalações locais.
- **Portas publicadas em `127.0.0.1`**, todas configuráveis no `.env`.
- **Postgres 18 monta o volume em `/var/lib/postgresql`** (mudança da imagem oficial na versão 18; montar em `/data` como nas versões anteriores perderia dados).
- **Nome do projeto Compose fixo** (`name: cs-tactical`), isolando redes e volumes de outros projetos.
- **`HOST_UID`/`HOST_GID` em vez de `UID`/`GID`**: `UID` é variável somente-leitura do bash e causaria conflito.
- **`make up` usa `--wait`**: só retorna quando todos os serviços estão saudáveis.
- **`make destroy`** é o único comando que apaga volumes e exige digitar `apagar`.
- `POSTGRES_PASSWORD` é obrigatória (`:?`): o Compose falha com mensagem clara se faltar.

### Comandos executados

```bash
make setup
make doctor
make up
docker compose exec -T postgres psql -U cs_tactical -tAc "select datname from pg_database ..."
docker compose exec -T redis redis-cli ping
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8025/
make down && make up        # persistência
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make doctor` em ambiente limpo | ✔ todos os itens (aviso esperado de "não é WSL" no ambiente de validação) |
| `make up` | ✔ os 3 serviços `healthy` em ~6 s |
| Bancos criados | ✔ `cs_tactical` e `cs_tactical_test` (PostgreSQL 18.6) |
| `data_directory` do Postgres | ✔ `/var/lib/postgresql/18/docker`, dentro do volume |
| Redis | ✔ `PONG`, `appendonly yes` |
| Mailpit UI | ✔ HTTP 200 em `127.0.0.1:8025` |
| Persistência | ✔ tabela criada, `make down` + `make up`, dado continua lá |
| Detecção de porta ocupada | ✔ com um processo na porta 3000, `make doctor` falha e indica `WEB_PORT` |

### Pendências

- Validar no seu WSL2 real: UID 1000, integração do Docker Desktop e acesso pelo navegador do Windows (o ambiente de validação é Linux sem WSL).
- O repositório ainda não está no GitHub (ver README da entrega).
