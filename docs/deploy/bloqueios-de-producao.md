# Bloqueios de produção

Itens que **impedem o deploy público** até serem resolvidos e verificados. Cada item tem origem,
risco, o que fazer e como verificar. Um item só sai desta lista com a verificação registrada.

> Ainda não existe ambiente de produção nem configuração de deploy. Esta lista é o critério de
> aceite para criá-los. As Fases 1 e 2 estão concluídas para **desenvolvimento**; **produção
> continua bloqueada** por P1–P4 e G1, sem mudança de situação na Fase 2 (auditorias:
> [fase-1-auditoria.md](../development/fase-1-auditoria.md),
> [fase-2-auditoria.md](../development/fase-2-auditoria.md#preparação-para-produção), que lista
> também o que a Academy pede da configuração de produção).

Material preparado na etapa 1.8 (modelos, nada implantado):

| Arquivo | Uso |
| --- | --- |
| [`infra/deploy/edge/nginx.conf.example`](../../infra/deploy/edge/nginx.conf.example) | borda de referência: TLS, HSTS, substituição do `X-Forwarded-For`, descarte de cabeçalhos de IP, roteamento `/api` e `/sanctum` direto para a API (P1, P2) |
| [`infra/deploy/production.env.example`](../../infra/deploy/production.env.example) | variáveis da API em produção, sem segredos (P3, P4) |
| `php artisan system:check-production-config` | verificação, só leitura, da configuração da aplicação (P3, P4); falha com código 1 |
| [`scripts/verify-edge.sh`](../../scripts/verify-edge.sh) | verificação externa contra o ambiente publicado (P1, P2, P4) |

## Abertos

### P1 — Proxy de borda como fonte do IP real

- **Origem:** [ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md), etapa 1.0.
- **Risco se ignorado (severidade alta):** um cliente escolhe o próprio IP e contorna o rate
  limiting de login, cadastro e recuperação de senha (força bruta, abuso de envio de e-mails).
- **Evidência (etapa 1.8):** na stack E2E, onde o Next.js recebe as requisições diretamente (sem
  borda), seis logins inválidos trocando o `X-Forwarded-For` a cada tentativa responderam 422 nas
  seis; com o mesmo IP, a sexta respondeu 429. O Next.js repassa o cabeçalho do cliente sem
  alteração, como previsto na ADR 0009. Em produção, só a borda pode impedir isso.
- **O que fazer:**
  - Borda com TLS na frente de tudo, **substituindo** `X-Forwarded-For` pelo IP da conexão
    (`proxy_set_header X-Forwarded-For $remote_addr;`) e definindo `X-Forwarded-Proto`.
  - Remover dos pedidos do cliente: `Forwarded`, `X-Forwarded-Host`, `X-Forwarded-Port`,
    `X-Forwarded-Prefix`, `X-Real-IP`, `Client-IP`, `True-Client-IP`, `CF-Connecting-IP`.
  - Encaminhar `/api/*` e `/sanctum/*` direto para a API e o restante para o Next.js.
  - Com CDN: restaurar o IP do cliente só a partir das faixas publicadas pela CDN.
  - HSTS na borda (a aplicação não o envia, ADR 0014).
  - Ponto de partida: `infra/deploy/edge/nginx.conf.example`.
- **Verificação:** `scripts/verify-edge.sh https://<domínio> <e-mail de conta de teste>` de fora da
  rede: a sexta tentativa de login com `X-Forwarded-For`, `X-Real-IP` e `Forwarded` forjados (e
  diferentes a cada vez) responde 429; HSTS presente; HTTP redireciona para HTTPS.

### P2 — Nenhum acesso público que contorne a borda

- **Origem:** ADR 0009.
- **Risco se ignorado (severidade alta):** quem acessa o Next.js ou a API diretamente envia
  `X-Forwarded-For` arbitrário (mesma evidência de P1).
- **O que fazer:** Next.js e API apenas em rede privada; firewall/security group liberando só a
  borda. Nenhuma porta publicada como no `compose.yaml` de desenvolvimento.
- **Verificação:** `scripts/verify-edge.sh` com `nmap` instalado mostra só 80/443 abertas;
  requisição direta ao IP/porta do Next.js e da API falha.

### P3 — `TRUSTED_PROXIES` mínimo e explícito

- **Origem:** ADR 0009.
- **Risco se ignorado:** uma faixa ampla (sub-rede compartilhada, faixa de nuvem) inclui endereços
  que outros podem usar para enviar cabeçalhos aceitos.
- **O que fazer:** listar só os IPs (ou a menor faixa possível) da borda e, se o Next.js chamar a
  API, dos containers do Next. Revisar ao mudar a topologia (réplicas, balanceador, CDN).
  **Procedimento de revisão** a cada mudança de topologia: (1) listar os saltos entre a borda e a
  API; (2) atualizar `TRUSTED_PROXIES` só com esses IPs; (3) rodar
  `system:check-production-config`; (4) rodar `verify-edge.sh` com conta de teste.
- **Verificação:** `php artisan system:check-production-config` no ambiente (falha se a lista
  estiver vazia ou tiver faixa maior que /24) e `php artisan config:show trustedproxy`.

### P4 — Configuração segura da aplicação

- **Origem:** relatório da Fase 0 (pendência de `APP_DEBUG`) e
  [autenticacao.md](../architecture/autenticacao.md#cookies).
- **O que fazer:** partir de `infra/deploy/production.env.example`: `APP_ENV=production`,
  `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `HEALTH_EXPOSE_DETAILS=false`,
  `SANCTUM_STATEFUL_DOMAINS` só com o domínio público, URLs em HTTPS, `LOG_LEVEL` diferente de
  `debug`, e-mail real. Imagem de produção sem dependências de desenvolvimento
  (`composer install --no-dev`, frontend sem `devDependencies` em runtime) e sem pcov/Xdebug.
- **Verificação:** `php artisan system:check-production-config` passa no ambiente;
  `verify-edge.sh` confirma cookies `Secure`, health sem `checks` e erros sem detalhes internos.

## Riscos de governança

### G1 — `main` sem proteção automática

- **Origem:** etapa 1.7 (PR #5).
- **Situação:** o repositório é privado numa conta gratuita, e o GitHub recusa branch protection
  e rulesets ("Upgrade to GitHub Pro or make this repository public"). Não há checks obrigatórios
  (API, Web, E2E) nem bloqueio técnico de push direto, force push ou exclusão da `main`: essas
  regras valem só por processo.
- **Risco:** uma mudança entrar na `main` sem CI verde ou sem revisão, ou o histórico ser reescrito
  por engano.
- **O que fazer:** decidir entre GitHub Pro, repositório público ou organização com plano Team; em
  seguida, criar um ruleset na `main` exigindo PR, os checks `API (Laravel)`, `Web (Next.js)` e
  `E2E (Playwright)`, e bloqueando force push e exclusão.
- **Verificação:** um push direto de teste à `main` é recusado; um PR com check falhando não pode
  ser mesclado.
- Não bloqueia o desenvolvimento; deve ser resolvido antes do primeiro deploy de produção.

## Resolvidos

Nenhum ainda.
