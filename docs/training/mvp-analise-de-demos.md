# MVP de telemetria: análise de partidas a partir de demos (plano)

> Planejamento (2026-10-23, branch `docs/demo-analysis-mvp`). **Nada implementado.** Escopo aprovado
> pelo responsável (F1 do [estudo T0.1](fontes-de-telemetria.md)); decisões técnicas na
> [ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md) (Proposta). Cada etapa só começa com
> autorização própria.

## O que o jogador ganha

1. Vincula a conta Steam em **Conta** (uma vez).
2. Baixa uma partida pelo próprio jogo (aba "Assistir" → suas partidas) e a envia em
   **Minhas partidas**.
3. Em alguns segundos, vê a análise **dele** naquela partida: disciplina de tiro com rifles (quanto
   atirou parado ou devagar), tiros, acertos, abates e mortes, por arma, com a explicação de que é
   uma **estimativa** e que o dado vem de um arquivo enviado por ele.
4. Acompanha a evolução entre partidas comparáveis (mesma versão da métrica).

Fora do MVP: download automático (Valve ou FACEIT), análise de outros jogadores, mapas de calor,
granadas e utilitários, comparação entre jogadores, rankings, recomendações da Academy, o plugin no
servidor (ADR 0021).

## Visão geral

```
Navegador ──► web (Next) ──/api──► api (Laravel, módulo Matches)
   │  envio em partes (CSRF)             │ monta o arquivo no disco matches-uploads
   │                                     │ confere assinatura, tamanho, cabeçalho
   │                                     │ enfileira (Redis) ──► worker (Laravel)
   │                                     │                         │ POST interno: {arquivo, steamid}
   │                                     │                         ▼
   │                                     │              analyzer (serviço isolado: sem internet,
   │                                     │              sem banco/Redis; volume só leitura;
   │                                     │              limites de CPU/memória/tempo)
   │                                     │                         │ JSON só com agregados do steamid
   │                                     │ ◄── valida schema ──────┘
   │                                     │ grava resultado; apaga a demo
   ◄── estado e resultado (polling) ─────┘
```

## Etapas

| Etapa | Escopo | Depende de | Testes obrigatórios | Concluída quando |
|---|---|---|---|---|
| **M0** Plano e ADR | Este documento e a ADR 0022 (Proposta) | T0.1 | Revisão; links válidos | Aprovação do responsável |
| **M1** PoC do analisador (**concluída em 2026-10-23**) | Repetir o E2 com `@laihoe/demoparser2` (TypeScript) e com `demoparser2` (Python), num container isolado, com 3 demos do responsável; definir o JSON de saída v1 | M0 | Mesmos números nos dois runtimes; tempo e memória por demo; demo sem o SteamID → erro claro | Runtime escolhido (D-M1); contrato v1 do JSON com *fixtures*; ADR 0022 atualizada |
| **M2** Vínculo Steam (T3) | OpenID 2.0 no `Identity`: `user_steam_accounts`, iniciar/concluir vínculo, desvincular, tela em `/painel/conta`; política de privacidade | M0 (independe de M1) | Pest: resposta adulterada, `return_to` trocado, `claimed_id` fora do padrão, replay de nonce, SteamID já vinculado a outra conta, desvínculo; E2E com a Steam simulada | Vínculo e desvínculo com a matriz de segurança verde |
| **M3** Envio | Módulo `Matches`: `match_uploads` (estado, tamanho, hash, partes), rotas de iniciar/enviar parte/concluir/cancelar, disco `matches-uploads`, limites por usuário, conferência do arquivo, limpeza de envios abandonados | M2 | Pest: sem sessão/CSRF, parte fora de ordem, repetida, maior que o limite, arquivo final maior que 600 MB, assinatura falsa, envio de outro usuário (404), cota diária, limpeza; concorrência (duas conclusões simultâneas) | Arquivo montado e conferido; nada sobra no disco depois de cancelar ou expirar |
| **M4** Análise | Serviço `analyzer` no Compose (rede só interna com a API/worker, sem saída), job no worker, schema do resultado, `match_analyses` (métricas do jogador, versões, estado), apagar a demo depois | M1, M3 | Contrato com *fixtures* (JSON válido, campo extra, tipo errado, SteamID ausente); Pest do job (sucesso, tempo esgotado, analisador fora, resposta inválida → falha sem resultado parcial, demo apagada em todos os casos); sondagem: o analisador não alcança a internet nem o banco | Demo de *fixture* gera o resultado esperado; arquivo sempre apagado |
| **M5** Telas | "Minhas partidas": enviar (progresso por parte, cancelar), estados (enviando, na fila, analisando, pronta, recusada, falhou), página da análise com explicação de estimativa e não verificado, evolução entre partidas comparáveis | M4 | Vitest dos estados; E2E com analisador simulado e *fixture* pequena; axe nos dois temas; teclado; celular | Jogador envia, acompanha e lê a análise; nenhuma violação séria de acessibilidade |
| **M6** Revisão | Segurança (sondagem como a da 2.7b: IDOR, CSRF, limites, arquivo malicioso, *path traversal*), privacidade (nada de terceiros gravado ou em log), desempenho (demo de 450 MB) e documentação | M5 | Sondagem versionada em `scripts/`; medição de tempo/memória | Achados corrigidos ou aceitos; relatório do MVP |

M2 e M1 podem andar em paralelo (arquivos diferentes). M3 depende de M2 (sem vínculo não há envio).

## Resultado da M1 (2026-10-23)

**Método.** As três demos mais recentes do responsável (matchmaking da Valve: de_mirage, de_dust2 e
de_nuke; 242, 218 e 163 MB; patches 14184 e 14181), lidas da pasta `replays` do jogo e copiadas uma
por vez para uma pasta de laboratório (cópia apagada logo depois). O mesmo contrato implementado duas
vezes, com o mesmo núcleo em Rust: Python (`demoparser2` 0.42.0) e TypeScript em Node 24
(`@laihoe/demoparser2` 0.42.0, executado com remoção de tipos, sem build). Imagens descartáveis
(rede só para montá-las; apagadas no fim); cada análise **sem rede**, sistema de arquivos só leitura,
6 GB e 4 CPUs.

| Demo | Python: tempo no processo / pico | TypeScript: tempo no processo / pico | Resultado |
|---|---|---|---|
| 1 (de_mirage) | 3,74 s / 444 MB | 2,88 s / 370 MB | idêntico |
| 2 (de_dust2) | 3,25 s / 408 MB | 2,92 s / 337 MB | idêntico |
| 3 (de_nuke) | 3,45 s / 352 MB | 2,61 s / 279 MB | idêntico, menos **um** percentual: 6,25 → `6.2` (Python) × `6.3` (JS) |

Com a subida do container, o tempo total foi 4,2–4,6 s (Python) e 6,6–9,7 s (TypeScript): o Node
gasta mais para carregar. Imagens: 901 MB (Python, com pandas e pyarrow) × 349 MB (Node).

**Achados.**

1. A única divergência foi de **arredondamento**: o Python arredonda empates para o par
   (`round(6.25, 1) = 6.2`) e o `Math.round` do JavaScript para cima (6,3). O contrato passa a fixar
   a regra: percentuais e velocidades com uma casa, **metade para longe do zero**, calculados a
   partir de inteiros (contagens) sempre que possível.
2. `velocity` do parser fora, como no E2: a velocidade derivada da posição não descartou nenhum tiro
   nas três partidas.
3. Os números de jogo das três partidas (tiros 346/262/217; rifles parado 51,8/41,8/46,4%; abaixo de
   73 u/s 84,1/87,3/90,2%) vieram iguais nos dois runtimes.

**D-M1 (aprovada pelo responsável em 2026-10-23):** **TypeScript.** Mesmos resultados, menos memória, imagem com
~40% do tamanho e a mesma cadeia de ferramentas do frontend (pnpm, Vitest, ESLint). Os segundos a
mais na subida do container não pesam num processo de longa duração (o serviço fica no ar e recebe
pedidos). Python fica como alternativa se o binding do Node deixar de acompanhar o parser.

### Contrato `cst-demo-analysis/v1` (proposto pela M1)

Entrada: caminho da demo (só leitura) e o SteamID64 vinculado. Saída: um JSON; jogador ausente da
partida → `{"error": "player_not_in_demo"}`.

| Campo | Conteúdo |
|---|---|
| `schema` | `"cst-demo-analysis/v1"` |
| `demo` | `map`, `patch_version`, `valve_server` (nome do servidor começa com "Valve Counter-Strike 2"), `tickrate` |
| `totals` | `shots` (sem faca, granadas, C4 e zeus), `hits` e `kills` (jogador atacante, outra vítima), `deaths`, `discarded_shots` (sem posição ou acima de 400 u/s) |
| `weapons[]` | `weapon`, `class` (rifle, sniper, smg, heavy, pistol), `shots`, `speed` (`p50`, `p75`, `p90`), `stationary_pct` (< 5 u/s); ordem: tiros desc., nome |
| `rifles` | `shots`, `speed`, `stationary_pct`, `below_73_pct` (limiar provisório, D-M5) |
| `runtime` | parser e versão, linguagem, tempo, memória (fora de qualquer comparação) |

Regras: velocidade = distância horizontal entre a posição no tick do tiro e no anterior × 64;
percentil na posição `floor(p × n)` da lista ordenada; arredondamento de uma casa, metade para longe
do zero. Nenhum nome ou SteamID de outro jogador na saída.

## Métrica v1

| Métrica | Origem | Tipo (ADR 0021, item 6) | Observação |
|---|---|---|---|
| Tiros, acertos, abates, mortes por arma | eventos `weapon_fire`, `player_hurt`, `player_death` | observada (na demo) | faca e granadas fora dos tiros |
| Velocidade horizontal no tiro (p50, p75, p90) por classe de arma | posição no tick do tiro e no anterior × 64 | **estimativa** | nunca a propriedade `velocity` do parser (E2) |
| % de tiros de rifle praticamente parado (< 5 u/s) e abaixo do limiar de precisão | idem | **estimativa** | limiar por arma a calibrar (D-M5); até lá, exibir só "parado" |

Comparabilidade: só entre análises com a mesma versão da calculadora e da métrica; uma versão nova
abre outra série (ADR 0021, item 9).

## Segurança e privacidade (resumo)

| Risco | Controle |
|---|---|
| Arquivo malicioso explorando o parser | Analisador isolado: sem internet, sem banco/Redis, usuário sem privilégio, sistema de arquivos só leitura, limites de CPU/memória/tempo; parser fixado por versão e atualizado com revisão |
| Esgotar disco ou CPU | Tamanho máximo por arquivo e por parte, um envio aberto por usuário, cota diária, limpeza de envios abandonados, fila com concorrência limitada |
| Ver a partida ou o resultado de outro usuário | Policies por posse; 404 para recurso alheio (padrão do Learning) |
| Dados de terceiros (nove outros jogadores) | Só agregados do SteamID vinculado saem do analisador; nada de nomes/SteamIDs alheios no banco ou nos logs; demo apagada depois da análise |
| Demo adulterada pelo jogador | Resultado `unverified`, só para o próprio jogador, sem ranking nem uso externo |
| Vínculo Steam falsificado | OpenID verificado no backend (`check_authentication`), nonce único, `return_to` e `realm` fixos |

## Decisões em aberto

| # | Decisão | Proposta |
|---|---|---|
| D-M1 | Runtime do analisador | **Decidida em 2026-10-23: TypeScript** (Node 24 com `@laihoe/demoparser2`), depois da M1 ([resultado](#resultado-da-m1-2026-10-23)); Python fica como alternativa se o binding do Node deixar de acompanhar o parser |
| D-M2 | Armazenamento dos envios | Disco local `matches-uploads` em desenvolvimento; S3 com URL pré-assinada em produção |
| D-M3 | Limites | 600 MB por arquivo, partes de 8 MB, um envio aberto, 5 análises por dia (ajustar depois da M6) |
| D-M4 | Retenção | Demo apagada logo após a análise (máx. 24 h em falha); métricas enquanto a conta existir, apagadas em cascata |
| D-M5 | Limiar de precisão por arma | Fonte: documentação/comunidade + T2 do plugin; até lá, só "parado" |
| D-M6 | Ordem em relação à Fase 2 | M1 e M2 agora, ou depois de 2.7c, 2.7d e 2.8 |
| D-M7 | Texto da política de privacidade | Antes da M2 ir para qualquer ambiente com usuários reais |

## Riscos

- Mudança do formato de demo num patch do CS2 quebra o parser até ele ser atualizado (mitigação:
  versão fixada, *fixtures* de contrato, estado "falhou" claro para o jogador).
- A métrica de velocidade pode não refletir o que o jogo usa (H2): por isso é estimativa.
- Produção continua bloqueada por P1–P4 e G1; o MVP roda só em desenvolvimento até lá.
