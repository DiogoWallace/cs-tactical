# Bloqueios de produção

Itens que **impedem o deploy público** até serem resolvidos e verificados. Cada item tem origem, o que precisa ser feito e como verificar. Um item só sai desta lista com a verificação registrada.

> Ainda não existe ambiente de produção nem configuração de deploy. Esta lista é o critério de aceite para criá-los.

## Abertos

### P1 — Proxy de borda como fonte do IP real

- **Origem:** [ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md), etapa 1.0.
- **Risco se ignorado:** um cliente escolhe o próprio IP e contorna o rate limiting de login, cadastro e recuperação de senha (força bruta, abuso de envio de e-mails).
- **O que fazer:**
  - Borda com TLS na frente de tudo, **substituindo** `X-Forwarded-For` pelo IP da conexão (`proxy_set_header X-Forwarded-For $remote_addr;`) e definindo `X-Forwarded-Proto`.
  - Remover dos pedidos do cliente: `Forwarded`, `X-Forwarded-Host`, `X-Forwarded-Port`, `X-Forwarded-Prefix`, `X-Real-IP`, `Client-IP`, `True-Client-IP`, `CF-Connecting-IP`.
  - Encaminhar `/api/*` e `/sanctum/*` direto para a API (topologia recomendada da ADR 0009) e o restante para o Next.js.
  - Com CDN: restaurar o IP do cliente só a partir das faixas publicadas pela CDN.
- **Verificação:**
  - De fora da rede, `curl -H "X-Forwarded-For: 1.2.3.4" -H "Forwarded: for=1.2.3.4" -H "X-Real-IP: 1.2.3.4"` contra uma rota de diagnóstico (a criar junto com o deploy, sem dados sensíveis) mostra o IP real, nunca `1.2.3.4`.
  - Sexta tentativa de login inválido em 1 minuto responde 429, mesmo trocando o `X-Forwarded-For` a cada tentativa.

### P2 — Nenhum acesso público que contorne a borda

- **Origem:** ADR 0009.
- **Risco se ignorado:** quem acessa o Next.js ou a API diretamente envia `X-Forwarded-For` arbitrário, e o Laravel o aceita vindo de um proxy confiável.
- **O que fazer:** Next.js e API apenas em rede privada; firewall/security group liberando só a borda. Nenhuma porta publicada como no `compose.yaml` de desenvolvimento.
- **Verificação:** varredura de portas a partir da internet mostra apenas 80/443 da borda; requisição direta ao IP/porta do Next.js e da API falha.

### P3 — `TRUSTED_PROXIES` mínimo e explícito

- **Origem:** ADR 0009.
- **Risco se ignorado:** uma faixa ampla (sub-rede compartilhada, faixa de nuvem) inclui endereços que outros podem usar para enviar cabeçalhos aceitos.
- **O que fazer:** listar só os IPs (ou a menor faixa possível) da borda e, se o Next.js chamar a API, dos containers do Next. Revisar ao mudar a topologia (réplicas, balanceador, CDN).
- **Verificação:** `php artisan config:show trustedproxy` no ambiente mostra só esses endereços.

### P4 — Configuração segura da aplicação

- **Origem:** relatório da Fase 0 (pendência de `APP_DEBUG`) e [autenticacao.md](../architecture/autenticacao.md#cookies).
- **O que fazer:** `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `HEALTH_EXPOSE_DETAILS=false`, `SANCTUM_STATEFUL_DOMAINS` só com o domínio público.
- **Verificação:** erro 500 provocado não mostra `exception`/`trace`; cookies com atributo `Secure`; `/api/v1/health` sem o bloco `checks`.

## Resolvidos

Nenhum ainda.
