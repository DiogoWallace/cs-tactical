# 0012 — Autorização: capacidades de plataforma e Policies por recurso

- Status: Aceita (implementada na etapa 1.6)
- Data: 2026-10-16

## Contexto

A Fase 1 tem três papéis de plataforma (`user`, `editor`, `admin`, [ADR 0007](0007-papeis-de-equipe-e-funcoes-no-jogo.md)). A Academy (Fase 2) precisa saber quem publica conteúdo, e o Teams (Fase 3) terá permissões por equipe. Comparar o papel direto no código (`$user->platform_role === Admin`) espalha a regra e facilita um atalho perigoso: o admin "pode tudo", inclusive ler dados privados de equipes.

## Decisão

1. **Capacidades, não papéis.** O código autoriza por capacidades nomeadas (`PlatformAbility`), cada uma um Gate com o mesmo nome:

   | Capacidade (Gate) | `user` | `editor` | `admin` |
   |---|---|---|---|
   | `manage-academy-content` | — | ✔ | ✔ |
   | `administer-platform` | — | — | ✔ |

   A matriz fica em `PlatformRole::abilities()`, a única fonte. O `IdentityServiceProvider` registra um Gate por capacidade a partir dela.
2. **Só o módulo Identity conhece `PlatformRole`.** Os outros módulos usam `Gate::authorize('manage-academy-content')`, o middleware `can:manage-academy-content` ou `$user->can(...)`. Testes de arquitetura impedem o uso do enum e de `platform_role` fora do Identity.
3. **Sem `Gate::before` nem `Gate::after`.** Nenhum papel libera tudo. Um teste de arquitetura impede esses atalhos.
4. **Dados de um recurso: Policy do recurso.** Posse (perfil, credenciais) e, na Fase 3, participação em equipe são verificadas por Policies (`ProfilePolicy`, `UserPolicy`, futura `TeamPolicy`). Capacidade de plataforma não entra nessas Policies: **ser admin não dá acesso à conta de outra pessoa nem aos dados privados de uma equipe.**
5. **Toda rota da API exige `auth:sanctum`**, exceto uma lista pública explícita (health, cadastro, login, recuperação de senha, cookie CSRF). Um teste de arquitetura percorre as rotas e falha com qualquer rota nova sem decisão; outro exige rate limiting nas rotas públicas que alteram estado.
6. **Mudança de papel só pelo comando `identity:set-role`** na Fase 1 (sem tela administrativa). Ele pede confirmação, recusa rebaixar o último admin e registra a mudança no log. O papel novo vale na próxima requisição.
7. **`GET /api/v1/me` expõe `abilities`**, as capacidades efetivas calculadas pelos Gates, só para o frontend mostrar ou esconder elementos. O frontend nunca autoriza: a API decide cada ação.

## Alternativas consideradas

- **Pacote de papéis e permissões em tabela** (ex.: spatie/laravel-permission): flexível, mas acrescenta tabelas e cache para três papéis fixos e duas capacidades. Reavaliar se surgirem papéis configuráveis.
- **`Gate::before` para admin**: simples, mas contraria a ADR 0007 e tornaria cada Policy futura uma exceção a lembrar.
- **Checar o papel nos controllers**: espalha a regra e não tem um ponto único de teste.

## Consequências

- Uma capacidade nova é um caso no enum, uma linha na matriz e uma linha no teste da matriz; nenhum controller muda.
- A matriz é testada por papel × capacidade (Gates e HTTP), e `/me` acompanha os Gates.
- O guia de uso para os próximos módulos está em [docs/architecture/autorizacao.md](../architecture/autorizacao.md).
