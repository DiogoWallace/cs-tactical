# Cobertura de testes

```bash
make coverage
```

Gera os relatórios da API e do frontend. **Não há percentual mínimo** nesta fase: os números servem
para encontrar lacunas, não para reprovar o build.

| Parte | Ferramenta | Mede | Relatórios |
| --- | --- | --- | --- |
| API | pcov (imagem de desenvolvimento, desligado por padrão; ligado com `-d pcov.enabled=1`) | linhas, métodos | texto no terminal, HTML em `apps/api/build/coverage/`, Clover em `apps/api/build/coverage/clover.xml` |
| Frontend | `@vitest/coverage-v8` | linhas, statements, funções, branches | texto, HTML em `apps/web/coverage/`, `coverage-summary.json` |

**Branches da API:** o pcov não mede branches. A medição pontual usa Xdebug num container
temporário, sem mudar a imagem (é lenta, ~10 min):

```bash
docker compose run --rm --no-deps -u root -e XDEBUG_MODE=coverage api sh -c "install-php-extensions xdebug && php -d memory_limit=4G vendor/bin/pest --path-coverage --coverage-text"
```

Não rode dois `pest` ao mesmo tempo: os dois usam o banco `cs_tactical_test`, e o
`migrate:fresh` de um apaga as tabelas do outro.

## Medição inicial (etapa 1.8, 2026-10-17)

| Parte | Linhas | Funções/métodos | Branches |
| --- | --- | --- | --- |
| API (Pest, 311 testes) | **93,2%** (476/511) | **92,0%** (92/100) com pcov | **84,6%** (230/272), medição pontual com Xdebug |
| Frontend (Vitest, 104 testes) | **71,6%** (383/535) | **56,1%** (101/180) | **66,9%** (239/357) |

Ao fim da etapa 1.8 (326 testes, incluindo os de `system:check-production-config`): API
**93,8%** de linhas (532/567) e **92,2%** de métodos (94/102); frontend sem mudança.

Com Xdebug o percentual de métodos cai para 83,0%: ele conta um método como coberto só quando
todos os seus caminhos foram exercitados.

### Áreas com menor cobertura

API (linhas):

| Arquivo | Linhas | Observação |
| --- | --- | --- |
| `System/Console/PrepareE2eDatabaseCommand.php` | 30% | o caminho de sucesso só roda na stack E2E (coberto por `make e2e`); as recusas têm testes |
| `Identity/Actions/UpdateProfile.php` | 80% | ramo de violação de unicidade concorrente do apelido |
| `Identity/Validation/AccountRules.php` | 84,6% | `rethrowUniqueViolation` para restrições não mapeadas |
| `Identity/Actions/ConfirmEmailChange.php` | 88,9% | corrida com outra conta tomando o e-mail entre o pedido e a confirmação |
| `Support/Http/ApiErrorResponse.php` | 93,5% | tipos de exceção raros |

Frontend:

| Área | Linhas | Observação |
| --- | --- | --- |
| páginas e layouts (`app/**`), `app-header`, `redirect-if-authenticated`, `get-current-user` | 0% | Server Components assíncronos: o Vitest/jsdom não os renderiza. Cobertos pelos 32 testes E2E |
| `features/system/**` (página `/status`) | 0% | sem testes unitários; coberta por E2E de acessibilidade |
| `components/ui/**` | 57,7% | componentes do shadcn/ui; variantes não usadas |
| `features/identity/**` | 81,6% | formulários e componentes de conta |
| `lib/**` | 86 a 100% | cliente da API, rotas seguras, headers de segurança |

### Próximos passos sugeridos (não bloqueantes)

- Testes de concorrência para as corridas de unicidade (apelido, e-mail) no grupo `concurrency`.
- Testes unitários da página `/status` (`features/system`).
- Avaliar meta mínima de cobertura numa fase futura, por área (ex.: Identity ≥ 90%), sem incluir
  Server Components.
