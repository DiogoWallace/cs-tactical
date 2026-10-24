# Fontes de telemetria do CS2 (estudo T0.1)

> Estudo de 2026-10-23 (branch `docs/telemetry-t0-1`). **Nada aqui está implementado na
> aplicação.** Experimento executado: E2 (análise de uma demo, [resultado](#resultado-do-e2-2026-10-23)). Complementa a [viabilidade](viabilidade-cs2.md), a
> [arquitetura](arquitetura.md), o [contrato de telemetria](telemetria.md) e a
> [ADR 0021](../decisions/0021-arquitetura-do-cs-tactical-training.md) (Proposta).

## Por que este estudo

O planejamento do Training (T0, 2026-10-17) cobre **uma** fonte: um plugin no nosso servidor de
treino. "Telemetria real do jogo" pode significar mais coisas, com dados, confiança e custos muito
diferentes. Este estudo compara as três fontes viáveis antes de escolher o primeiro MVP.

Classificação das afirmações, como na [viabilidade](viabilidade-cs2.md):
**Confirmada** (documentação oficial ou do próprio projeto, ou medição nossa) · **Provável**
(fontes de terceiros consistentes) · **Não confirmada** (contraditória ou sem fonte; vira
experimento).

## Resumo

| | A. Plugin no nosso servidor | B. Game State Integration (GSI) | C. Demos (`.dem`) |
|---|---|---|---|
| Onde gera dado | Só nos servidores de treino que nós hospedamos | Em **qualquer** partida no PC do jogador (inclusive competitivo/Premier) | Partidas com demo: matchmaking da Valve, FACEIT, nossos servidores |
| Granularidade | Por tick (64/s): velocidade, botões, tiros, dano, gatilhos de mapa | Por evento de estado do **jogador local**: vida, armas, rodada, placar. Sem posição durante a partida (evidência abaixo) | Por tick, de **todos** os jogadores: posição, velocidade, botões, tiros, mortes, granadas |
| Quando | Durante a sessão | Ao vivo | **Depois** da partida |
| Confiança no dado | Média: servidor nosso, mas um servidor comprometido assina dados falsos (ADR 0021, item 3) | **Baixa**: sai do PC do jogador; trivial de forjar | Alta para análise (arquivo do jogo, verificável); a identidade vem do share code / FACEIT |
| Identidade do jogador | Forte: `AuthorizedSteamID` validado pela Steam | Fraca: `steamid` no JSON, declarado pelo cliente | Média: o share code e o código de autenticação vêm da conta Steam do jogador |
| Custo para começar | **Alto**: servidor ~60–65 GB, Metamod + CounterStrikeSharp, quebras por patch | **Baixo**: um `.cfg` oficial e um receptor local | **Médio**: parser + armazenamento + processamento |
| Risco de manutenção | Alto (cada patch do CS2) | Baixo (recurso estável desde o CS:GO) | Médio (formato muda com o jogo; parsers comunitários acompanham) |
| Valor para o jogador | Exercícios guiados com métricas precisas | Retorno ao vivo simples (ex.: "comprou sem colete"), registro de partidas | Análise rica de partidas reais (mira, posicionamento, utilitários) |

## A. Plugin no nosso servidor (Metamod + CounterStrikeSharp)

Já estudado em [viabilidade-cs2.md](viabilidade-cs2.md) e [arquitetura.md](arquitetura.md).
Atualização de 2026-10-23:

| Ponto | Classificação | Evidência |
|---|---|---|
| CounterStrikeSharp acompanha os patches com releases frequentes | **Confirmada** | Releases v1.0.369 (30/05, migra para .NET 10) até v1.0.378 (23/10), cada uma citando o build do CS2 que corrige (1.41.6.9 … 1.41.9.2) ([releases](https://github.com/roflmuffin/CounterStrikeSharp/releases)) |
| Patches do CS2 quebram o plugin com frequência | **Confirmada** | Issues de 2026: símbolo indefinido após update (abril, julho), v1.0.374 sem carregar nos builds 1.41.7.4+ (setembro) ([#1277](https://github.com/roflmuffin/CounterStrikeSharp/issues/1277), [#1342](https://github.com/roflmuffin/CounterStrikeSharp/issues/1342), [#1415](https://github.com/roflmuffin/CounterStrikeSharp/issues/1415)) |
| Versão do Metamod precisa casar com a do CounterStrikeSharp | **Provável** | v1.0.375 passou a usar KHook e exige Metamod 2.0 build ≥ 1467, segundo um gerenciador de servidores ([PR #44](https://github.com/Auto-Tournament/cs2-server-manager/pull/44)); conferir no CHANGELOG antes de fixar versões |
| Laboratório local (T1) cabe na máquina de desenvolvimento | **Não, hoje** | O servidor pede ~60–65 GB; o disco C: (onde também fica o disco do WSL) tinha ~19 GB livres em 2026-10-23. Alternativas: liberar espaço, disco externo ou VM na nuvem (decisão pendente) |

Concorrência: a Refrag mostra estatísticas por sessão dos **seus** servidores de treino desde maio
de 2026 e, desde junho, puxa partidas reais de Premier e FACEIT para comparação ("Refrag Coach")
([Sessions](https://refrag.gg/blog/introducing-sessions-a-new-era-of-cs2-training-on-refrag),
[Coach](https://refrag.gg/blog/what-is-refrag-coach/)). Ou seja: o mercado combina A com C.

## B. Game State Integration (GSI)

Recurso oficial da Valve (herdado do CS:GO): o **cliente** do jogo envia `POST` com JSON a um
endereço HTTP configurado num arquivo `gamestate_integration_<nome>.cfg`.

| Ponto | Classificação | Evidência |
|---|---|---|
| Arquivo `gamestate_integration_<nome>.cfg` em `<jogo>/game/csgo/cfg`, com `uri`, `timeout`, `buffer`, `throttle`, `heartbeat` e a lista de componentes (`provider`, `map`, `round`, `player_*`, `allplayers_*`, `bomb`, `allgrenades`, `phase_countdowns`…) | **Provável** | Página da Valve (escrita para o CS:GO; recusou acesso automatizado em 2026-10-23) e bibliotecas de CS2 que usam o mesmo formato ([CounterStrike2GSI](https://github.com/antonpup/CounterStrike2GSI)) |
| Jogando, só o **jogador local** é exposto; dados de todos os jogadores (`allplayers`) só como espectador/observador | **Provável** | README do CounterStrike2GSI e guias de terceiros |
| `player_position` **não** chega durante uma partida competitiva | **Provável (medição de terceiros)** | Sondagem publicada em 21/10/2026: 596 payloads de uma partida competitiva em de_dust2 com `player_position` pedido; nenhum trazia posição (vivo, morto assistindo um colega, morto na própria câmera) e `allplayers` nunca apareceu ([grovic/csbot#12](https://github.com/grovic/csbot/pull/12)) |
| Campos de estado do jogador (vida, armadura, capacete, dinheiro, armas e munição, abates da rodada, estatísticas da partida) e frequência de envio | **Não confirmada** | Só fontes secundárias, algumas contraditórias (ex.: "a cada tick" e "só quando o estado muda" no mesmo texto); medir no experimento E1 |
| Usar GSI não arrisca VAC | **Provável** | O GSI não injeta código (só um `.cfg` oficial e HTTP local); a orientação da Valve sobre risco trata de injeção de código. A Steam não certifica ferramentas de terceiros; nenhuma fonte oficial encontrada sobre GSI e VAC |

Consequências para o produto:

- Sem posição e sem dado por tick, **o GSI não mede counter-strafe nem mira**. Serve para registro
  de partidas, economia, retorno ao vivo simples e para **associar** partidas reais ao jogador.
- O dado vem do PC do jogador: qualquer métrica derivada só pode ser apresentada como **declarada
  pelo cliente** e nunca entrar em comparações entre jogadores (mesma regra de confiabilidade da
  ADR 0021, item 7).
- Exige um **agente local** no PC do jogador (o jogo só fala com um endereço configurado), que
  repassaria os dados à API: novo componente, nova superfície de segurança (credencial por usuário,
  não por servidor) e de privacidade.

## C. Demos (`.dem`)

| Ponto | Classificação | Evidência |
|---|---|---|
| `demoparser2` (Rust, com bindings Python e JS, licença MIT) lê demos do CS2 como "consulta a banco": propriedades por tick (`X`, `Y`, `Z`, `velocity_X/Y/Z`, `buttons` e derivados como `FIRE`, `LEFT`, `RIGHT`) e eventos (`parse_event`) | **Confirmada** | README do projeto ([LaihoE/demoparser](https://github.com/LaihoE/demoparser)) |
| Desempenho: 4,6 GB (50 demos de MM, FACEIT e HLTV) em ~6 s num PC de 12 núcleos | **Confirmada (benchmark do projeto)** | Idem |
| Tiros (`weapon_fire`) e dano (`player_hurt`) disponíveis como eventos | **Provável** | Usados pelo `awpy`, que se apoia no `demoparser2` ([awpy](https://awpy.readthedocs.io/en/stable/reference.html)); nem toda demo traz todos os eventos |
| Matchmaking da Valve: demo das últimas 8 partidas no cliente; o link expira ~30 dias depois da partida | **Provável** | Guias de ferramentas ([CS Demo Manager](https://cs-demo-manager.com/docs/guides/downloads), [Leetify](https://leetify.com/blog/share-codes/)) |
| Busca automática das próximas partidas com **código de autenticação de jogo** + último share code (`GetNextMatchSharingCode`), autorizados pelo próprio jogador numa página da Steam | **Provável** | Fluxo usado pela Leetify, com a página oficial da Steam para gerar os códigos ([Leetify](https://leetify.com/blog/share-codes/), [ajuda da Steam](https://help.steampowered.com/en/wizard/HelpWithGameIssue/?appid=730&issueid=128)); a documentação da Valve do endpoint não foi verificada |
| FACEIT: URL da demo nas respostas do endpoint de partidas da Data API, download por URL assinada (Downloads API) e webhook "Match Demo Ready" | **Confirmada (doc. da FACEIT)** | [Guias da FACEIT](https://docs.faceit.com/getting-started/category/guides); requisitos de acesso e limites a verificar |
| Velocidade no tick do tiro, extraída da demo, igual à usada pelo jogo (hipótese **H2** da [viabilidade](viabilidade-cs2.md)) | **Não confirmada** | O E2 mostrou que a propriedade `velocity` do parser não serve e que a velocidade pela posição é consistente; se ela é a que o jogo usa para a precisão só a comparação com o plugin (T2) responde. Ver [E2](#resultado-do-e2-2026-10-23) |
| Demo de matchmaking grava o estado do jogador a cada tick | **Confirmada (E2)** | 120.602 de 120.616 intervalos entre linhas do jogador vivo são de 1 tick; nenhuma posição repetida com o jogador em movimento |

Consequências:

- As demos dão a análise mais rica de **partidas reais** sem servidor nosso e sem código no PC do
  jogador. É o caminho dos analisadores existentes (Leetify, CS Demo Manager, Refrag Coach).
- Custo: armazenar e processar arquivos grandes (centenas de MB por partida), fila de
  processamento, retenção curta (dado pessoal de **todos** os jogadores da partida, não só do
  usuário: impacto de privacidade a tratar).
- Também é a ferramenta de calibração do plugin (A) prevista na T2.

## Leitura combinada

- **A** mede exercícios controlados com precisão; **C** mede partidas reais; **B** só acompanha o
  estado do jogador local, com dado não confiável. As três não competem: A e C se complementam, B
  é acessório.
- **C é o MVP mais barato com dado real e verificável**: não depende de servidor de 60 GB, de
  plugin que quebra a cada patch nem de software no PC do jogador. A identidade vem da conta Steam
  (códigos autorizados pelo próprio jogador) ou da FACEIT.
- **A continua necessário** para exercícios guiados (counter-strafe com retorno no jogo), mas o
  laboratório está bloqueado pelo disco.
- **B** só entra se houver um caso de uso ao vivo que justifique um agente local.

## Experimentos (cada um pede confirmação; E2 executado em 2026-10-23)

| # | Experimento | O que responde | Precisa da confirmação para |
|---|---|---|---|
| **E1** | GSI: criar `gamestate_integration_cstactical_lab.cfg` na pasta `game/csgo/cfg` do CS2 apontando para `http://127.0.0.1:<porta>`; um receptor local (script fora da aplicação) só grava os JSON em arquivo; o jogador joga ~10 min (bots e uma partida real, se quiser) | Campos reais, frequência, tamanho; se há posição jogando; confirmação local da sondagem de 21/10 | Criar o arquivo na pasta do CS2 e rodar o receptor local |
| **E2** | Demo: parsear **uma demo do próprio jogador** com o `demoparser2` num container isolado e descartável | Velocidade e botões no tick de cada tiro (H2), eventos disponíveis, tempo e memória de processamento | Baixar a demo (pelo cliente do jogo) e instalar o `demoparser2` (PyPI) no container |
| **E3** | Laboratório do servidor (T1 da [roadmap](roadmap.md)) | Hipóteses H1–H8 | Decidir onde rodar (espaço em disco ou nuvem) e autorizar a T1 |

Ordem sugerida: E2 (maior valor, sem tocar no jogo), depois E1 (rápido, valida B), e E3 quando
houver disco.

## Resultado do E2 (2026-10-23)

**Método.** Uma demo de matchmaking do próprio jogador (de_mirage, servidor da Valve em São Paulo,
25/09/2026, 241 MB, `valve_demo_2`, patch 14184), copiada da pasta `replays` do jogo (só leitura)
para uma pasta de laboratório no WSL. `demoparser2` 0.42.0 numa imagem descartável
(`python:3.12-slim`), análise **sem rede**, com a demo montada só leitura, limite de 6 GB e 4 CPUs.
Só agregados do dono da demo (identificado pelo SteamID da conta local); nenhum dado de outros
jogadores foi impresso. A imagem e a cópia da demo foram apagadas no fim.

| Medida | Resultado |
|---|---|
| Tempo total (cabeçalho, 49 tipos de evento, `weapon_fire`/`player_hurt`/`player_death`, propriedades por tick dos tiros) | **4,6 s**; pico de memória **442 MB** |
| Eventos disponíveis | 49, entre eles `weapon_fire`, `fire_bullets`, `player_hurt`, `player_bullet_hit`, `bullet_damage`, `player_death`, `player_footstep`, `weapon_zoom`, granadas, bomba e rodadas |
| Volume da partida | 4.165 tiros, 717 eventos de dano, 166 mortes; o jogador: 480 `weapon_fire` (346 com arma de fogo; faca e granadas também geram o evento), 63 acertos |
| Propriedades por tick | Recebidas: posição, `velocity_X/Y/Z`, teclas `FORWARD/BACK/LEFT/RIGHT`, `is_scoped`, arma ativa. **`WALK` não veio**, embora listada pelo projeto |
| Propriedade `velocity` no tick do tiro | **Não confiável**: em 346 tiros com arma, 29 (8%) `NaN` e 82 (24%) acima de 400 u/s; na partida inteira só 62 `NaN` em 120.616 ticks, ou seja, as anomalias se concentram nos tiros. Onde existe, diverge da velocidade pela posição em até ~195 u/s (p90) |
| **Velocidade pela posição** (tick anterior → tick do tiro, × 64) | **Consistente**: 276/276 tiros de rifle utilizáveis; p50 2,1, p75 44,3, p90 76,5 u/s; 51,8% praticamente parado (< 5 u/s) e 84,1% abaixo de 73 u/s (limiar aproximado de precisão do rifle, ainda a calibrar) |

**Conclusões.**

1. Demos de matchmaking **dão o dado para uma métrica de counter-strafe** em partidas reais: a
   posição de cada jogador a cada tick e o tick de cada tiro, processados em segundos.
2. A métrica precisa usar a **velocidade derivada da posição**, nunca a propriedade `velocity` do
   parser, e descartar faca e granadas dos tiros. Esse é o tipo de armadilha que só aparece medindo.
3. Continua em aberto (H2) se a velocidade pela posição é a que o jogo usa para a imprecisão no
   momento do tiro (o CS2 tem *subtick*). A resposta exige a comparação com o plugin no servidor
   (T2) ou a documentação do jogo. Até lá, uma métrica derivada de demo seria **estimativa**
   (ADR 0021, item 6).
4. Custo: 241 MB por partida e ~5 s de CPU para esta análise; a ingestão de demos precisa de fila e
   retenção curta (decisão F3).

## Decisões em aberto

| # | Decisão |
|---|---|
| F1 | **Decidida em 2026-10-23**: demos enviadas manualmente pelo jogador, com vínculo Steam ([plano do MVP](mvp-analise-de-demos.md), [ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md)). O download automático pela Valve foi descartado por ora: baixar o arquivo exige um cliente Steam logado no Game Coordinator (conta-bot no servidor) |
| F2 | Fontes de demo aceitas: matchmaking da Valve (códigos do jogador), FACEIT, upload manual |
| F3 | Retenção e privacidade das demos (dados de todos os jogadores da partida) |
| F4 | Onde rodar o laboratório do servidor (E3/T1) |
| F5 | Se o resultado mudar o desenho da ADR 0021: emenda (nova seção "fontes") ou ADR nova para a ingestão de demos |
