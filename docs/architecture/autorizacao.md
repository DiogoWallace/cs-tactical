# Autorização

> Decisões: [ADR 0007](../decisions/0007-papeis-de-equipe-e-funcoes-no-jogo.md) (papéis de plataforma, de equipe e função no jogo) e [ADR 0012](../decisions/0012-autorizacao-por-capacidades-e-policies.md) (capacidades e Policies).

A autorização acontece **sempre no backend**. O frontend usa `abilities` de `/api/v1/me` só para mostrar ou esconder elementos; a API decide cada ação.

## Camadas

| Pergunta | Ferramenta | Onde | Exemplo |
|---|---|---|---|
| Está autenticado? | `auth:sanctum` | rota | toda rota da API, exceto as públicas listadas no teste de rotas |
| Tem a capacidade de plataforma? | Gate por `PlatformAbility` | rota (`can:`) ou Form Request | publicar uma aula: `manage-academy-content` |
| Pode agir **sobre este recurso**? | Policy do recurso | Form Request (`authorize()`) ou controller (`Gate::authorize`) | editar o próprio perfil: `ProfilePolicy@update` |
| Pertence à equipe / qual o papel nela? (Fase 3) | `TeamPolicy` + `TeamRole` | Policy | ver a estratégia de uma equipe |

As camadas se somam: uma rota de conteúdo da Academy exige `auth:sanctum`, `can:manage-academy-content` e, para editar uma aula específica, a Policy da aula.

## Capacidades de plataforma

| Capacidade (Gate) | `user` | `editor` | `admin` |
|---|---|---|---|
| `manage-academy-content` | — | ✔ | ✔ |
| `administer-platform` | — | — | ✔ |

A matriz fica em `App\Modules\Identity\Enums\PlatformRole::abilities()`.

### Como usar num módulo novo

```php
// Rota (preferível quando a regra vale para a rota inteira)
Route::post('/courses', StoreCourseController::class)
    ->middleware(['auth:sanctum', 'can:manage-academy-content']);

// Form Request
public function authorize(): bool
{
    return $this->user()?->can('manage-academy-content') === true;
}

// Action ou controller
Gate::authorize('manage-academy-content');
```

Nunca compare o papel (`platform_role`) fora do módulo Identity: o teste de arquitetura falha.

### Como acrescentar uma capacidade

1. Novo caso em `PlatformAbility` (nome em kebab-case, com um verbo: `moderate-comments`).
2. Incluir nos papéis que a concedem, em `PlatformRole::abilities()`.
3. Atualizar `PLATFORM_MATRIX` em `tests/Feature/Authorization/PlatformAuthorizationTest.php` e a tabela acima.
4. Se o frontend precisar, o tipo `PlatformAbility` em `apps/web/src/features/identity/types.ts`.

## Policies por recurso

- Uma Policy por model, em `app/Modules/<Modulo>/Policies/<Model>Policy.php`, registrada com `Gate::policy()` no provider do módulo.
- A Policy decide por **posse ou participação**, não por papel de plataforma. `ProfilePolicy` e `UserPolicy` só permitem o próprio usuário, para qualquer papel. `UserPolicy@manageSteamLink` (etapa M2) segue a mesma regra para vincular e desvincular a conta Steam: nenhum papel abre o vínculo de outra conta.
- Recursos de equipe (Fase 3) ficam em rotas aninhadas com `scopeBindings()` (`/teams/{team}/strategies/{strategy}`): recurso de outra equipe responde 404, e a `TeamPolicy` decide pelo `TeamRole` do membro. **`administer-platform` não abre dados privados de equipes.**
- Negar acesso responde 403 com `code: forbidden` (ou um `code` específico, como `email_not_verified`). Recurso de outra equipe responde 404, para não revelar que existe.

## Proibições (verificadas por testes)

| Regra | Teste |
|---|---|
| Rota da API sem `auth:sanctum` fora da lista pública | `tests/Feature/Authorization/RouteAuthorizationTest.php` |
| Rota pública que altera estado sem `throttle:` | idem |
| `Gate::before` / `Gate::after` | `tests/Unit/ArchitectureTest.php` |
| `PlatformRole` ou `platform_role` fora do Identity | idem |
| Policy fora de `*/Policies` ou sem o sufixo `Policy` | idem (Identity; replicar ao criar módulos) |

## Testes de autorização

Cada capacidade e cada Policy nova ganha um teste em **matriz**: todos os papéis × a ação, com o resultado esperado de cada combinação (ver `PlatformAuthorizationTest`). Testar só o caso permitido não basta: o caso negado é o que protege.

## Mudança de papel

```bash
make artisan c="identity:set-role pessoa@exemplo.com editor"
```

Pede confirmação (`--force` dispensa), recusa rebaixar o último admin e registra no log (`identity.platform_role_changed`). Vale na próxima requisição do usuário.
