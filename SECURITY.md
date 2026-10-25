# Política de segurança

## Como reportar uma vulnerabilidade

Não abra issue pública. Use o reporte privado do GitHub:
**Security → Report a vulnerability** neste repositório
(<https://github.com/DiogoWallace/cs-tactical/security/advisories/new>).

Inclua o que for possível: componente afetado (rota, tela, comando), passos para reproduzir,
impacto esperado e a versão ou commit testado. Não inclua dados pessoais de terceiros nem
credenciais reais.

## Escopo

- Código deste repositório (`apps/api`, `apps/web`, `infra/`, `scripts/`) e a configuração da CI.
- Ainda não existe ambiente de produção: o CS Tactical roda só em desenvolvimento
  ([bloqueios de produção](docs/deploy/bloqueios-de-producao.md)). Não teste contra endereços que
  não sejam o seu próprio ambiente local.

## Como o projeto trata

- Confirmação do recebimento e avaliação de severidade pelo responsável do repositório.
- Correção pelo procedimento de hotfix ([release-policy.md](docs/governance/release-policy.md)),
  com teste de regressão, e publicação de um advisory quando aplicável.
- Dependências: alertas do Dependabot, atualizações de segurança automáticas e secret scanning
  com push protection estão ativos; a triagem segue
  [dependencias.md](docs/development/dependencias.md#alertas-de-segurança).
