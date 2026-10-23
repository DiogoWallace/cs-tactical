# Conteúdo da Academy para os testes E2E

Importado pelo `scripts/e2e.sh` (`academy:import /var/www/e2e-content/academy`) só na stack E2E
isolada; o banco e o conteúdo de desenvolvimento não são usados nem alterados. Independe de
`content/academy`: mudar um curso real não quebra os testes.

| Curso                            | Estado                      | O que cobre                                                                                                                                                                                |
| -------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `fundamentos-e2e` (iniciante)    | publicado                   | capa; módulo 1 com aula completa (Markdown, imagem, callout, quiz) e aula com vídeo e imagem decorativa; aula em rascunho; módulo 3 em rascunho com aula publicada (oculta pelo ancestral) |
| `taticas-avancadas` (avançado)   | publicado                   | sem capa; filtro por nível                                                                                                                                                                 |
| `curso-rascunho` (intermediário) | rascunho                    | nunca aparece; filtro "intermediário" vazio                                                                                                                                                |
| `historico-e2e` (iniciante)      | publicado, depois arquivado | histórico de curso arquivado em "Meu progresso" (etapa 2.7a); nunca aparece nos testes como público                                                                                        |

`historico-e2e` passa por três passos no `scripts/e2e.sh`, antes do Playwright:

1. a importação normal o publica (a pasta `_arquivado` é ignorada, como toda entrada com `_`);
2. `e2e:prepare --seed-history` faz a conta `history@e2e.test` concluir as duas aulas pela Action
   do Learning (matrícula automática e curso concluído);
3. `academy:import .../_arquivado` reimporta o mesmo curso (mesmas `key`) com `status: archived`.

Ao mudar `historico-e2e`, mude a cópia em `_arquivado/historico-e2e` do mesmo jeito: só o
`status` (e o `summary`) do `course.yaml` podem ser diferentes.

As imagens (PNG pequenos) são geradas por `_generate-images.py`. O vídeo usa um ID fictício; os
testes interceptam o player e nunca dependem da internet.
