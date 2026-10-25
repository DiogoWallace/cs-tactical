# 0022 — Análise de partidas a partir de demos enviadas pelo jogador

- Status: Proposta (escopo aprovado em 2026-10-23, decisão F1 do [estudo T0.1](../training/fontes-de-telemetria.md); PoC M1 concluída e runtime decidido; aceite depende das demais decisões em aberto do [plano do MVP](../training/mvp-analise-de-demos.md))
- Data: 2026-10-23

## Contexto

O estudo T0.1 comparou três fontes de telemetria do CS2 (plugin no nosso servidor, Game State
Integration e demos) e executou o experimento E2 com uma demo real de matchmaking:

- a demo grava o estado de cada jogador **a cada tick** e o tick de cada tiro; o `demoparser2`
  processou 241 MB em 4,6 s com 442 MB de memória;
- a propriedade `velocity` do parser é inválida em ~32% dos tiros; a velocidade **derivada da
  posição** é consistente em 100% dos tiros de rifle;
- se essa velocidade é a que o jogo usa para a imprecisão do tiro (hipótese H2, *subtick*) ainda
  não está confirmado.

O responsável escolheu como primeiro MVP de telemetria **demos enviadas manualmente pelo jogador,
com vínculo da conta Steam** (F1, opção 1), em vez de baixar demos automaticamente pela Valve
(exigiria uma conta Steam automatizada conversando com o Game Coordinator: risco de termos de uso
e sem biblioteca confirmada para o CS2), pela FACEIT (depende de aprovação de terceiros) ou do
plugin no servidor (bloqueado por disco e por quebras a cada patch; continua na
[ADR 0021](0021-arquitetura-do-cs-tactical-training.md)).

Restrições: produção bloqueada por P1–P4 e G1; a Academy (Fase 2) ainda tem 2.7c, 2.7d e 2.8; o
servidor de aplicação não deve executar código de terceiros sobre arquivos enviados por usuários
sem isolamento.

## Decisão

1. **Módulo próprio `Matches`** (análise de partidas, já previsto em
   [visao-geral.md](../architecture/visao-geral.md)), independente de `Academy`, `Learning` e
   `Training`. O vínculo Steam fica no `Identity` (como previsto na ADR 0021, item 4), e é
   compartilhado com o futuro `Training`. As definições de métrica seguem as regras da ADR 0021,
   itens 6–9 (tipo da métrica, versões, comparabilidade), num formato reaproveitável pelos dois
   módulos.
2. **Identidade**: o jogador vincula a conta Steam por **OpenID 2.0 verificado no backend**
   (`user_steam_accounts`, um SteamID por conta e vice-versa; implementado na M2,
   [mvp-m2-vinculo-steam.md](../training/mvp-m2-vinculo-steam.md#execução-2026-10-24)). Sem vínculo, não há envio. Uma demo
   só é analisada se o SteamID vinculado **estiver entre os jogadores da partida**; caso contrário,
   é recusada sem análise.
3. **Envio**: upload **manual** de um arquivo `.dem` (até 600 MB no MVP), **em partes** pela mesma
   origem (`/api`), autenticado, com CSRF e limites por usuário. Cada parte é pequena o bastante para
   o proxy do Next e para os limites do Nginx; a API monta o arquivo num disco próprio
   (`matches-uploads`, local em desenvolvimento, S3 depois, como o disco `academy`). O arquivo é
   conferido pelo conteúdo (assinatura de demo do Source 2, tamanho, leitura do cabeçalho) antes de
   entrar na fila. **Implementado na M3** ([plano](../training/mvp-m3-envio-de-demos.md)), com a
   decisão U4: a API confere só assinatura e tamanho; o cabeçalho é lido pelo analisador isolado
   (item 4), para a API não interpretar o arquivo do usuário.
4. **Análise isolada**: um **serviço de análise** separado da API, sem acesso à internet, ao banco
   ou ao Redis, que recebe o caminho de um arquivo num volume compartilhado **só leitura**, roda o
   parser com limites de CPU, memória e tempo, e devolve **só agregados do SteamID pedido**. A API
   valida a resposta por schema e grava o resultado; o serviço não grava nada. O runtime (TypeScript
   com `@laihoe/demoparser2` ou Python com `demoparser2`, o mesmo núcleo em Rust) é escolhido na PoC
   M1, que repete o E2 nos dois. **M1 (2026-10-23)**: resultados iguais em três partidas reais;
   TypeScript com menos memória e imagem menor. **Decidido: TypeScript** (Node 24 com
   `@laihoe/demoparser2`), aprovado pelo responsável em 2026-10-23; o contrato de saída é o
   `cst-demo-analysis/v1` ([resultado](../training/mvp-analise-de-demos.md#resultado-da-m1-2026-10-23)).
5. **O que é guardado**: só as **métricas derivadas do próprio jogador** e metadados da partida
   (mapa, data do arquivo, build, hash SHA-256 da demo para recusar repetidos). **A demo é apagada
   logo depois da análise** (no máximo 24 h se a análise falhar). Nomes, SteamIDs e dados de outros
   jogadores da partida **nunca** são gravados nem registrados em log.
6. **Métrica v1 e tipo**: disciplina de tiro com rifles (velocidade horizontal **derivada da
   posição** no tick do tiro; faca e granadas fora), tiros, acertos, abates e mortes. A velocidade é
   classificada como **estimativa** até a T2 confirmar a H2; nenhuma métrica do MVP entra em ranking,
   comparação entre jogadores ou certificação.
7. **Confiança**: uma demo enviada pelo jogador pode ter sido alterada. Os resultados são do jogador
   e para o jogador: estado **`unverified`** (além dos estados da ADR 0021, item 7), sem uso externo.
   Verificações de coerência (servidor oficial da Valve no cabeçalho, mapa conhecido, contagens
   plausíveis) só rebaixam o resultado; nunca o promovem a verificado.
8. **Versões**: cada resultado grava a versão do parser, do serviço de análise, da calculadora e o
   build/patch da demo; regras de comparabilidade da ADR 0021, item 9.

## Alternativas consideradas

- **Download automático pela Valve (share codes + código de autenticação)**: o código e o share code
  dão a lista de partidas, mas baixar o arquivo exige um cliente Steam logado no Game Coordinator.
  Uma conta-bot no servidor traz risco de termos de uso e de bloqueio, e não há biblioteca
  confirmada para o CS2. Fica para depois, se houver caminho oficial.
- **FACEIT Data API**: viável para quem joga FACEIT, mas exige app aprovado e vínculo de outra conta;
  segundo passo natural depois do upload.
- **Parser dentro da API (PHP chamando o binário) ou do Next**: executaria código de terceiros sobre
  arquivo do usuário no processo que tem sessão, banco e segredos. Vetado.
- **Guardar a demo para reprocessar**: ~200–450 MB por partida, com dados pessoais de nove outras
  pessoas. Reprocessar exige um novo envio.
- **Upload direto para S3 com URL pré-assinada**: bom em produção, mas não há S3 em desenvolvimento;
  fica como evolução do disco `matches-uploads`.
- **Usar a propriedade `velocity` do parser**: inválida em ~32% dos tiros no E2.

## Consequências

- O `Identity` ganha o vínculo Steam (T3 do roadmap do Training), que o `Training` reaproveita.
- A stack ganha um serviço novo (o de análise), com imagem própria, atualizações do parser a cada
  mudança do formato de demo e testes de contrato com *fixtures* (demos pequenas de laboratório).
- O `RouteAuthorizationTest` cobre as rotas novas (todas autenticadas, com `throttle:`); escrita
  de arquivo grande pede limites e testes de abuso (tamanho, quantidade, arquivo falso).
- Política de privacidade precisa citar o vínculo Steam e o processamento de demos antes de
  qualquer uso real (já exigido para a T3).
- Métricas do MVP aparecem como **estimativas** e **não verificadas**, com a explicação na tela.
- A PoC M1 já escolheu o runtime e reproduziu o E2; a ADR passa a Aceita depois das demais decisões
  em aberto do [plano](../training/mvp-analise-de-demos.md#decisões-em-aberto) (D-M2 a D-M7).
