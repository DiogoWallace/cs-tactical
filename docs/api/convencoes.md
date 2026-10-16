# Convenções da API

- Base: `/api/v1`. Mudanças incompatíveis geram `/api/v2`.
- Recursos no plural, kebab-case: `/training-sessions`, `/teams/{team}/members`.
- Recursos de equipe sempre aninhados sob `/teams/{team}` (com scoped bindings).
- JSON em `snake_case`; datas ISO 8601 em UTC; IDs como string UUID.
- Sempre enviar `Accept: application/json`.

## Respostas

```json
{ "data": { "id": "0199f1c2-...", "name": "..." } }
```

Listas paginadas:

```json
{ "data": [ ... ], "links": { ... }, "meta": { "current_page": 1, "per_page": 20, "total": 42 } }
```

## Erros

| Status | Quando | Corpo |
|---|---|---|
| 401 | não autenticado | `{ "message": "Unauthenticated." }` |
| 403 | sem permissão | `{ "message": "...", "code": "forbidden" }` |
| 404 | não encontrado (inclui recurso de outra equipe) | `{ "message": "...", "code": "not_found" }` |
| 419 | CSRF ausente ou expirado | `{ "message": "CSRF token mismatch." }` |
| 422 | validação | `{ "message": "...", "errors": { "campo": ["..."] } }` |
| 429 | rate limit | `{ "message": "Too Many Attempts." }` |
| 5xx | erro interno | `{ "message": "Server Error" }` (sem detalhes em produção) |

Erros de regra de negócio usam `code` estável e legível por máquina (ex.: `team_member_limit_reached`).

## Endpoints atuais

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| GET | `/up` | não | Liveness do Laravel (processo vivo) |
| GET | `/api/v1/health` | não | Readiness: verifica PostgreSQL e Redis |

### `GET /api/v1/health`

Verifica PostgreSQL (`select 1`) e Redis (`PING`). Responde `200` quando tudo está ok e `503` quando alguma dependência falha.

```json
{
  "status": "ok",
  "timestamp": "2026-10-16T02:54:58+00:00",
  "checks": {
    "database": { "status": "ok", "latency_ms": 12.27 },
    "redis": { "status": "ok", "latency_ms": 2.09 }
  }
}
```

O bloco `checks` só aparece quando `HEALTH_EXPOSE_DETAILS=true` (padrão apenas em `APP_ENV=local`). Mensagens de erro nunca vão para a resposta; ficam no log da API.
