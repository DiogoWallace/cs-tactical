# Política para agentes de desenvolvimento

Vale para qualquer agente automatizado que leia ou altere este repositório. O agente lê este
arquivo, [github-workflow.md](github-workflow.md) e [STATUS.md](../STATUS.md) antes de alterar
qualquer coisa.

## Antes de começar

- Trabalhar só a partir de uma issue em Ready, dentro do escopo dela; o que estiver fora vira
  issue nova, não mudança no mesmo PR.
- Conferir o estado atual no GitHub e no `git` (commits, PRs abertos, branches); nunca presumir que
  números ou commits vistos antes continuam iguais.
- Alterações locais não commitadas de outra pessoa ou sessão são preservadas: trabalhar num
  worktree ou clone separado, nunca descartá-las.

## Durante o trabalho

- Branch `tipo/numero-descricao` a partir de `dev` (ou `homolog`/`main` nos casos previstos).
- Conventional Commits; `Refs #n`, nunca `Closes #n` em commit.
- Seguir a arquitetura (`docs/architecture/`) e as ADRs; decisão nova relevante é proposta como
  ADR, não aplicada por conta própria.
- Exigem autorização explícita do responsável: migration em banco com dados, acesso a arquivos do
  jogo ou do usuário, instalar serviço novo, gastar dinheiro, apagar branch, fechar issue, mudar
  ruleset ou configuração do repositório, deploy.
- Proibido: force push, reescrever histórico, push direto em `dev`, `homolog` ou `main`, desligar
  teste ou verificação de segurança, bypass de proteção, inventar resultado de teste ou aprovação
  humana.

## Ao abrir o PR

- Template preenchido com o que foi de fato executado (comandos e resultados).
- Destino `dev` para implementação. Promoção e release são do responsável ou por pedido explícito
  dele.
- Comentários na issue só nos eventos previstos em [issue-policy.md](issue-policy.md#comentários).

## Autoria

- Usar a identidade Git e a conta GitHub já configuradas; não alterar autores nem histórico.
- **Não adicionar crédito ou atribuição automática a ferramentas de inteligência artificial** em
  commits, PRs, issues, comentários, documentação, templates ou relatórios: nada de
  `Co-Authored-By` de ferramenta, "Generated with/by", "Assisted by" ou equivalentes.
- Não inventar autoria humana, não manipular metadados do GitHub e não contornar obrigação
  explícita de transparência; havendo conflito, avisar o responsável.
- Texto técnico, objetivo, sem autopromoção.
