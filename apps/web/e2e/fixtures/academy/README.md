# Conteúdo da Academy para os testes E2E

Importado pelo `scripts/e2e.sh` (`academy:import /var/www/e2e-content/academy`) só na stack E2E
isolada; o banco e o conteúdo de desenvolvimento não são usados nem alterados. Independe de
`content/academy`: mudar um curso real não quebra os testes.

| Curso                            | Estado    | O que cobre                                                                                                                                                                                |
| -------------------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `fundamentos-e2e` (iniciante)    | publicado | capa; módulo 1 com aula completa (Markdown, imagem, callout, quiz) e aula com vídeo e imagem decorativa; aula em rascunho; módulo 3 em rascunho com aula publicada (oculta pelo ancestral) |
| `taticas-avancadas` (avançado)   | publicado | sem capa; filtro por nível                                                                                                                                                                 |
| `curso-rascunho` (intermediário) | rascunho  | nunca aparece; filtro "intermediário" vazio                                                                                                                                                |

As imagens (PNG pequenos) são geradas por `_generate-images.py`. O vídeo usa um ID fictício; os
testes interceptam o player e nunca dependem da internet.
