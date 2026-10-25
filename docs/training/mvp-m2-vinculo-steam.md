# M2 — Vínculo da conta Steam (plano de implementação)

> Planejamento de 2026-10-23 (branch `docs/demo-analysis-m2-plan`), **implementado em 2026-10-24**
> (branch `feat/m2-steam-link`; ver [Execução](#execução-2026-10-24)). Etapa M2 do
> [plano do MVP](mvp-analise-de-demos.md); decisões de base na
> [ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md) (item 2) e na
> [ADR 0021](../decisions/0021-arquitetura-do-cs-tactical-training.md) (item 4).

## Objetivo

O jogador logado prova que é dono de uma conta Steam e a vincula à conta do CS Tactical, para que a
análise de demos (M3–M5) saiba qual jogador da partida é ele. Um SteamID por conta e uma conta por
SteamID. Nada além do SteamID64 é guardado.

Fora da M2: nome, avatar ou perfil da Steam (exigiriam a Steam Web API e uma chave), login pela Steam
(entrar no site com a Steam), vínculo de outras plataformas (FACEIT), qualquer uso do SteamID em
telas públicas.

## Como funciona o OpenID 2.0 da Steam

| Ponto | Classificação | Fonte |
|---|---|---|
| A Steam é um provedor OpenID 2.0; endpoint de descoberta `https://steamcommunity.com/openid/` | **Confirmada** | [Steamworks: autenticação](https://partner.steamgames.com/doc/features/auth) |
| O `claimed_id` devolvido tem a forma `…steamcommunity.com/openid/id/<SteamID64>` | **Confirmada** | Idem (a documentação mostra `http://`; aceitar `http` e `https` e conferir o resto) |
| O endpoint de login (`openid.op_endpoint` da resposta) é `https://steamcommunity.com/openid/login` | **Provável** | Prática das bibliotecas; conferir com uma resposta real no início da implementação e fixar em configuração |
| Não é preciso chave da Steam Web API para o OpenID | **Provável** | O fluxo não usa chave; a documentação não exige |

Fluxo (sem biblioteca OpenID genérica: só o perfil fixo da Steam, como fazem as integrações
mantidas; menos superfície do que um cliente OpenID completo com descoberta):

```
Navegador (logado)            Next (/painel/conta)            API (Laravel, Identity)              Steam
   │ clica "Vincular Steam" ──► POST /api/v1/me/steam/link (CSRF) ──► gera state (32 bytes),
   │                                                                guarda na sessão (10 min),
   │ ◄──────────────── { redirect_url } ◄─────────────────────────── monta a URL de login
   │ window.location = redirect_url ─────────────────────────────────────────────────────────► login
   │ ◄──────────────── 302 para /painel/conta/steam?state=…&openid.* ◄──────────────────────── assina
   │ página de retorno lê a query, limpa o histórico (replaceState) e
   │ POST /api/v1/me/steam/link/complete { state, openid.* } (CSRF) ──► valida (abaixo)
   │                                                                POST check_authentication ──► is_valid
   │                                                                grava user_steam_accounts
   │ ◄──────────────── 200 { steam: { steam_id, linked_at } } ◄──── (ou 4xx com code)
```

Parâmetros da ida (`checkid_setup`): `openid.ns=http://specs.openid.net/auth/2.0`,
`openid.mode=checkid_setup`, `openid.return_to=<APP_URL>/painel/conta/steam?state=<state>`,
`openid.realm=<APP_URL>`, `openid.identity` e `openid.claimed_id` =
`http://specs.openid.net/auth/2.0/identifier_select`. `APP_URL` vem da configuração, nunca do
`Host` da requisição.

## Validação da resposta (`complete`)

Em ordem; qualquer falha encerra sem gravar nada e com um `code` estável:

| # | Regra | Falha → `code` |
|---|---|---|
| 1 | Sessão válida (rota com `auth:sanctum`) e CSRF | 401 / 419 |
| 2 | `state` presente, igual ao da sessão, dentro de 10 min; consumido na primeira tentativa (mesmo se falhar) | 422 `steam_state_invalid` |
| 3 | `openid.mode = id_res` (`cancel` = o jogador desistiu) | 422 `steam_cancelled` |
| 4 | `openid.ns` = OpenID 2.0; `openid.op_endpoint` = endpoint fixo da configuração | 422 `steam_response_invalid` |
| 5 | `openid.return_to` **idêntico** à URL montada com o `state` da sessão (protege contra uma resposta obtida em outra sessão) | 422 `steam_response_invalid` |
| 6 | `openid.claimed_id` = `openid.identity` e casa com `^https?://steamcommunity\.com/openid/id/(7656119\d{10})$` | 422 `steam_response_invalid` |
| 7 | `openid.signed` inclui pelo menos `op_endpoint`, `claimed_id`, `identity`, `return_to`, `response_nonce`, `assoc_handle` | 422 `steam_response_invalid` |
| 8 | `openid.response_nonce`: data dentro de ±5 min e nunca vista (Redis, chave por nonce, TTL 10 min) | 422 `steam_replay` |
| 9 | Verificação com a Steam: `POST` ao endpoint com os mesmos `openid.*` e `openid.mode=check_authentication`; só `is_valid:true` aceita. HTTPS, *timeout* de 5 s, sem seguir redirecionamentos, host fixo | 422 `steam_not_verified` / 503 `steam_unavailable` |
| 10 | Usuário ainda sem vínculo; SteamID sem dono (único no banco, em transação) | 409 `steam_already_linked` / 409 `steam_account_in_use` (sem revelar qual conta) |

Só os campos `openid.*` conhecidos são aceitos (o Form Request recusa o resto); o conjunto inteiro
de `openid.*` recebido vai, sem alteração, para o `check_authentication`.

## Banco

Migration `2026_10_xx_100000_create_user_steam_accounts_table` (módulo `Identity`):

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | uuid (UUIDv7) | PK |
| `user_id` | uuid | FK `users` **CASCADE**, **único** (uma conta Steam por usuário) |
| `steam_id` | varchar(17) | **único**; check `steam_id ~ '^7656119[0-9]{10}$'` |
| `linked_at` | timestamptz | não nulo |
| `created_at`, `updated_at` | timestamptz | |

Desvincular apaga a linha (não há histórico a preservar na M2; quando houver análises, elas ficam
ligadas ao usuário, não ao vínculo). Procedimento de migration no desenvolvimento como nas etapas
anteriores (backup, `pretend`, aprovação).

## API (módulo `Identity`)

| Método | Rota | Limite | Resposta |
|---|---|---|---|
| POST | `/api/v1/me/steam/link` | `steam-link` (10/min por usuário) | 200 `{ redirect_url }` (409 se já vinculado) |
| POST | `/api/v1/me/steam/link/complete` | `steam-link` | 200 `{ steam: { steam_id, linked_at } }` ou 4xx/503 com `code` |
| DELETE | `/api/v1/me/steam` | `credentials` (5/min, como as outras ações sensíveis da conta) | 204; exige a senha atual (decisão S2) |
| GET | `/api/v1/me` | `api` | campo novo `steam: { steam_id, linked_at } \| null` |

Todas com `auth:sanctum`, CSRF e `throttle:`; nenhuma entra na lista pública do
`RouteAuthorizationTest`. Policy por posse (só o próprio usuário; papéis de plataforma não abrem o
vínculo de outros, como `ProfilePolicy`).

Arquivos previstos:

| Arquivo | Papel |
|---|---|
| `app/Modules/Identity/Steam/SteamOpenId.php` | monta a URL de login e valida a resposta (regras 3–8), sem rede |
| `app/Modules/Identity/Steam/SteamOpenIdVerifier.php` | `check_authentication` (regra 9), único ponto com rede; trocável por um falso nos testes |
| `app/Modules/Identity/Actions/StartSteamLink.php`, `CompleteSteamLink.php`, `UnlinkSteamAccount.php` | casos de uso; `CompleteSteamLink` grava em transação e trata o único (regra 10) |
| `app/Modules/Identity/Models/UserSteamAccount.php` | model (`steam_id` não preenchível em massa) |
| `app/Modules/Identity/Http/Controllers/SteamLinkController.php`, `Http/Requests/CompleteSteamLinkRequest.php` | rotas acima; lista fechada de campos `openid.*` |
| `config/steam.php` | endpoint de login fixo, `realm`/`return_to` a partir de `APP_URL`, *timeout* |
| `database/migrations/…_create_user_steam_accounts_table.php`, `database/factories/Identity/UserSteamAccountFactory.php` | |

`system:check-production-config` ganha a conferência de que o endpoint da Steam é o oficial (HTTPS,
`steamcommunity.com`): um endpoint trocado em produção permitiria aceitar asserções de um provedor
falso.

## Frontend

| Arquivo | Papel |
|---|---|
| `src/features/identity/components/steam-link-card.tsx` (+ teste) | seção "Conta Steam" em `/painel/conta`: estado (não vinculada / vinculada com o SteamID64 e a data), botão "Vincular conta Steam" (POST e redirecionamento), "Desvincular" com confirmação e senha, mensagens por `code` |
| `src/app/(app)/painel/conta/steam/page.tsx` + `steam-link-return.tsx` (+ teste) | página de retorno: lê a query, apaga-a do histórico (`history.replaceState`, como na redefinição de senha), envia ao `complete`, mostra o resultado e volta à conta; nunca exibe parâmetros recebidos, só mensagens por `code` |
| `src/features/identity/api/account.ts` | `startSteamLink`, `completeSteamLink`, `unlinkSteam` |
| `src/features/identity/types.ts` | `steam` em `CurrentUser` |

Detalhes:

- Ir para a Steam por **navegação** (`window.location.assign`), não por formulário: a CSP tem
  `form-action 'self'`, que bloquearia um `POST` para outro domínio, e não deve ser afrouxada.
- Botão em texto ("Vincular conta Steam"); as imagens oficiais "Sign in through Steam" podem ser
  usadas, mas não são necessárias.
- A página de retorno fica sob `/painel` (protegida pelo `proxy.ts`): sem sessão, vai ao login e o
  `state` vence (o jogador recomeça).
- Acessibilidade: resultado anunciado (`role="status"`/`role="alert"`), foco no título da página de
  retorno, confirmação de desvínculo em diálogo acessível.

## Testes

**Pest (Feature e Unit), com o verificador falso e `Http::fake` para a Steam:**

| Grupo | Casos |
|---|---|
| Início | sem sessão 401; sem CSRF 419; URL com `realm`, `return_to` (do `APP_URL`, com `state`), `identifier_select`; `state` novo a cada início; já vinculado 409; limite 429 |
| Validação (regras 2–8) | `state` ausente, diferente, expirado, reutilizado; `mode=cancel`; `ns` errado; `op_endpoint` de outro host; `return_to` com outro `state` ou outro host; `claimed_id` de outro host, com `http://evil`, com SteamID fora do formato, diferente de `identity`; `signed` sem `return_to` ou sem `response_nonce`; nonce repetido; nonce com data antiga ou futura; campo `openid.*` desconhecido |
| Verificação (regra 9) | `is_valid:false`; Steam fora do ar ou lenta (503, nada gravado); resposta com redirecionamento (não seguido); o `POST` leva exatamente os campos recebidos e `mode=check_authentication` |
| Gravação (regra 10) | sucesso; SteamID já de outra conta (409, sem revelar a conta); usuário já vinculado; `GET /me` com o campo `steam`; apagar a conta apaga o vínculo (CASCADE) |
| Desvínculo | exige senha (errada 422), CSRF e sessão; 204; vincular de novo depois funciona |
| Autorização | `RouteAuthorizationTest` (rotas autenticadas e com limite); Policy por posse em matriz de papéis |
| Banco | migration: único de `user_id` e de `steam_id`, check do formato, CASCADE, rollback |
| Configuração | `system:check-production-config` falha com endpoint fora da Steam |

**Concorrência** (`tests/Concurrency`, processos reais): dois usuários concluindo o vínculo do mesmo
SteamID ao mesmo tempo → um grava, o outro recebe 409 `steam_account_in_use`.

**Vitest:** cartão (não vinculada, vinculada, erros por `code`, desvínculo com senha), página de
retorno (sucesso, cada `code`, limpeza da query, sem refletir parâmetros).

**E2E:** a stack E2E não tem internet. Um **provedor OpenID falso** só na stack E2E (decisão S1,
aprovada) permite o fluxo completo: vincular, cancelar, SteamID já em uso, desvincular; axe nos dois
temas.

## Segurança (resumo)

| Ameaça | Controle |
|---|---|
| Vincular o Steam de outra pessoa (resposta OpenID capturada ou forjada) | `state` por sessão e de uso único, `return_to` idêntico, `check_authentication` com a Steam, nonce único |
| Atacante faz a vítima vincular o Steam **dele** (CSRF de vínculo) | início por `POST` com CSRF; a resposta só vale na sessão que gerou o `state` |
| Provedor falso ou endpoint trocado | endpoint fixo em configuração, conferido em produção; `op_endpoint` e host do `claimed_id` verificados |
| Redirecionamento aberto | `return_to` sempre `APP_URL/painel/conta/steam`; nenhum destino vindo do cliente |
| Vazar que um SteamID tem conta | erro único `steam_account_in_use`, sem detalhes |
| SSRF pela verificação | só `https://steamcommunity.com`, sem redirecionamentos, *timeout* curto |
| Dados pessoais | só o SteamID64; apagado com a conta; política de privacidade antes de usuários reais (D-M7) |

## Documentação a atualizar na implementação

[autenticacao.md](../architecture/autenticacao.md) (fluxo do vínculo), [modelo-conceitual.md](../database/modelo-conceitual.md)
(`user_steam_accounts`), [autorizacao.md](../architecture/autorizacao.md) (Policy), [e2e.md](../development/e2e.md)
(provedor falso, se aprovado), este plano (execução) e a [ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md).

## Critérios de aceite

- Todas as regras 1–10 com teste que falha se a regra for removida (contraprova em pelo menos 3:
  `state`, `return_to` e `check_authentication`).
- Pest, concorrência, Vitest, lint, build e E2E verdes; nenhuma rota nova sem autenticação ou limite.
- Vínculo real feito uma vez no desenvolvimento pelo responsável, com a própria conta Steam
  (confirma a regra 4 com uma resposta real).
- Nenhum dado além do SteamID64 gravado; desvínculo e exclusão de conta removem o vínculo.

## Decisões

Aprovadas pelo responsável em 2026-10-23 (o plano foi aprovado; a implementação continua exigindo
autorização própria).

| # | Decisão | Situação |
|---|---|---|
| S1 | E2E do fluxo completo | **Aprovada**: provedor OpenID falso só na stack E2E (contêiner pequeno que assina como a Steam e responde ao `check_authentication`), com o endpoint configurável **só** fora de produção |
| S2 | Desvincular exige a senha | **Aprovada**: sim, como trocar e-mail e senha (ação que muda a identidade usada nas análises) |
| S3 | SteamID64 completo na tela da conta | **Aprovada**: só para o próprio usuário (é o identificador público da Steam); nunca em telas de outros |
| S4 | Ordem | **Aprovada**: M2 antes da M3 (sem vínculo não há envio) |

## Execução (2026-10-24)

Implementado como planejado, com as adaptações abaixo.

| Ponto do plano | Implementado | Motivo |
|---|---|---|
| `return_to` e `realm` a partir de `APP_URL` | `FRONTEND_URL` (`config('app.frontend_url')`), o mesmo dos links de e-mail; nunca o `Host` da requisição | A página de volta é do Next; o `APP_URL` da API aponta para a própria API |
| Corpo do `complete` com `openid.*` | `{ state, openid: { ns, mode, … } }`, sem o prefixo; o servidor remonta `openid.*` para conferir e para o `check_authentication` | A validação do Laravel lê o ponto como aninhamento; o conjunto enviado à Steam continua idêntico ao recebido |
| Confirmação de desvínculo em diálogo | Seção de confirmação no próprio cartão (`form` com nome acessível, foco na senha, confirmar e cancelar) | O projeto não tem componente de diálogo e o `<dialog>` nativo não roda no jsdom dos testes |
| Provedor falso (S1) | Serviço `steam-openid` só no `compose.e2e.yaml`, script Node sem dependências na mesma imagem do `web` ([e2e.md](../development/e2e.md#vínculo-da-conta-steam)) | Sem download de imagem nova; o endpoint só é configurável fora de produção (`config/steam.php`) |
| Erros com `code` | Exceção `SteamLinkFailed` com `render()` (status e `code` próprios, mensagem fixa); códigos em [convencoes.md](../api/convencoes.md#vínculo-da-conta-steam-etapa-m2) | Mesmo formato `{message, code}` do resto da API |

Arquivos: `app/Modules/Identity/Steam/{SteamOpenId,SteamOpenIdVerifier,SteamLinkFailed}.php`,
`Actions/{StartSteamLink,CompleteSteamLink,UnlinkSteamAccount}.php`,
`Http/Controllers/SteamLinkController.php`, `Http/Requests/{CompleteSteamLinkRequest,UnlinkSteamRequest}.php`,
`Http/Resources/SteamAccountResource.php`, `Models/UserSteamAccount.php`, `config/steam.php`,
migration `2026_10_24_100000_create_user_steam_accounts_table`, `UserPolicy@manageSteamLink`,
limitador `steam-link`, item novo no `system:check-production-config`; no frontend,
`steam-link-card.tsx`, `steam-link-return.tsx`, `/painel/conta/steam`, `lib/steam-link.ts`.

| Critério de aceite | Resultado |
|---|---|
| Regras 1–10 com teste; contraprova em `state`, `return_to` e `check_authentication` | `SteamLinkTest` (44 testes). Contraprovas: sem a conferência do `state`, 1 teste falha; sem a regra 5, 2 falham; sem o `check_authentication`, 2 falham. Arquivos restaurados |
| Pest, concorrência, Vitest, lint, build e E2E verdes; nenhuma rota sem autenticação ou limite | ver o PR; `SteamLinkConcurrencyTest` (dois usuários, o mesmo SteamID); `steam.spec.ts` (3 testes) |
| Vínculo real feito uma vez no desenvolvimento pelo responsável | **feito em 2026-10-25**, com a migration aplicada no banco de desenvolvimento (backup e `--pretend` antes) e a conta Steam do responsável: a Steam respondeu com o `op_endpoint` da configuração (regra 4 confirmada) e só o SteamID64 foi gravado. Na primeira tentativa a sessão do CS Tactical não valia mais na volta da Steam (`/me` → 401, novo login) e a conclusão foi recusada com `steam_state_invalid`, como previsto pela regra 2; a segunda tentativa vinculou. Causa não comprovada pelos logs: hipótese de o navegador não ter enviado o cookie de sessão (`SameSite=Lax`) na volta depois do formulário de login da Steam. Investigar se repetir |
| Só o SteamID64 gravado; desvínculo e exclusão de conta removem o vínculo | sim (testes de gravação, desvínculo e CASCADE) |
