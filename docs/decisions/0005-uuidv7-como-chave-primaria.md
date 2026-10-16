# 0005 — UUIDv7 como chave primária das entidades de domínio

- Status: Aceita
- Data: 2026-10-16

## Contexto

IDs sequenciais nas URLs facilitam enumeração e ataques de IDOR, e expõem volume de dados. UUIDs aleatórios (v4) fragmentam índices B-tree.

## Decisão

Tabelas de domínio usam **UUIDv7** como chave primária, no tipo nativo `uuid` do PostgreSQL, gerado pelo trait `HasUuids` do Laravel (que gera UUIDv7). Tabelas técnicas do framework (jobs, cache, sessions) mantêm o padrão do Laravel.

## Alternativas consideradas

- **bigint interno + UUID público**: duas colunas por tabela e mais cuidado em cada consulta.
- **UUIDv4**: inserções espalhadas no índice.

## Consequências

- IDs não enumeráveis nas URLs; ordenação temporal aproximada pelo próprio ID.
- UUID não substitui autorização: toda rota continua passando por Policies.
