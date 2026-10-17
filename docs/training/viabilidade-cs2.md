# Viabilidade técnica no ecossistema CS2

> **Etapa T0 (pesquisa), 2026-10-17.** Documento de planejamento: nada aqui está implementado,
> instalado ou contratado. As fontes foram consultadas nessa data; a documentação da Valve e do
> ecossistema muda com as atualizações do jogo, então cada item deve ser reconferido no laboratório
> (etapa T1, [roadmap.md](roadmap.md)).

## Como ler as classificações

| Classificação | Significado |
|---|---|
| **Confirmada** | Está na documentação oficial (Valve/Steamworks) ou na documentação do próprio projeto (CounterStrikeSharp, Metamod). |
| **Provável (PoC)** | Há documentação de terceiros, precedente no ecossistema ou dedução direta da API documentada, mas falta medir no laboratório. |
| **Não confirmada** | Nenhuma fonte encontrada. Não pode ser prometida. |
| **Inviável/restrita** | Contraria a documentação, uma política da Valve ou a arquitetura proposta. |

Regra aplicada em todo o módulo: **nenhuma métrica ou evento vale só porque existia no CS:GO.**

## Resumo

| Pergunta | Resposta curta |
|---|---|
| Dá para hospedar servidores CS2 próprios? | **Sim (confirmada).** Servidor dedicado oficial via SteamCMD, Linux ou Windows, com token GSLT. |
| Dá para rodar código próprio no servidor? | **Sim**, por dois caminhos: plugin C# (Metamod + CounterStrikeSharp, comunitário) e `cs_script` (JavaScript oficial, embutido no mapa). |
| Dá para saber **quem** está jogando com segurança? | **Sim (confirmada).** O servidor valida o jogador com a Steam; o plugin lê o `AuthorizedSteamID`. A conta do site se vincula à Steam por OpenID 2.0. |
| Dá para enviar resultados à API? | **Provável (PoC).** O `cs_script` não tem rede; o plugin C# roda .NET completo e há plugins publicados que enviam eventos por HTTP. |
| Dá para medir counter-strafe, mira, reflexo e circuitos? | **Parcialmente.** Velocidade, tiros, dano, botões e gatilhos de mapa são observáveis **por tick** (64/s). Precisão abaixo do tick e "tempo de reação humano" **não** são observáveis. Ver [metricas.md](metricas.md). |
| Mapas próprios? | **Sim (confirmada)**, com Workshop Tools e Hammer (Windows, GPU com ray tracing). Distribuição pela Steam Workshop: provável (PoC) para itens não públicos. |
| Maior risco | **Atualizações do CS2** quebram Metamod/CounterStrikeSharp com frequência, e o servidor não pode ficar na versão antiga porque os clientes deixam de conectar. |

Existe precedente comercial: a Refrag oferece servidores privados de treino CS2 por assinatura e,
desde maio de 2026, estatísticas por sessão ("Sessions"). O produto é viável, mas o mercado tem
concorrente estabelecido (ver [visao-geral.md](visao-geral.md#posicionamento)).

## 1. Servidor dedicado CS2 e SteamCMD

| Capacidade | Classificação | Evidência e limites |
|---|---|---|
| Instalar o servidor com SteamCMD (`login anonymous`, `app_update 730 validate`) | Confirmada | Valve Developer Community. Servidor e cliente foram unificados no app 730 (o 740 era do CS:GO). |
| Linux suportado | Confirmada | Requer glibc 2.31 (Ubuntu 20.04, Debian 11, EL9 ou mais novos). Executar por `cs2.sh -dedicated`. |
| Requisitos mínimos | Confirmada (mínimo) | 2 GB de RAM, ~60–65 GB de disco, CPU x86-64-v2. Uso real por instância e por jogador: **medir na T1**. |
| Atualizar a cada patch do jogo | Confirmada | A Valve diz que os passos de instalação valem "toda vez que o jogo é atualizado". Servidor desatualizado não recebe clientes atualizados (relato consistente de terceiros). |
| GSLT para outros jogadores entrarem | Confirmada | Token gerado em `steamcommunity.com/dev/managegameservers` com app 730, passado por `+sv_setsteamaccount`. |
| Desligar VAC (`-insecure`) | Confirmada | Útil só no laboratório; em produção o servidor roda seguro (ver identidade). |
| Modo LAN (`sv_lan 1`) | Confirmada | "sem heartbeat, sem autenticação". **Inviável para resultados oficiais**: sem autenticação Steam, o SteamID não é confiável. |
| Senha de servidor, RCON | Provável (PoC) | RCON existe e é usado por painéis; **não** será canal da aplicação (ver [seguranca.md](seguranca.md)). |
| Container Docker | Confirmada (comunitária) | A própria página da Valve cita a imagem `joedwards32/cs2` (2 CPUs, 2 GiB, 60 GB; atualiza o jogo ao iniciar; suporte a Workshop "experimental"). |
| Várias instâncias por máquina, arquivos do jogo compartilhados | Provável (PoC) | Ferramenta "CS2 Multiserver" citada pela Valve. Densidade por núcleo: **não há número confiável**; medir. |
| Gravação de demo (GOTV) no servidor | Provável (PoC) | Porta SourceTV (27020) documentada pela imagem Docker. Parsers de demos CS2 existem (`demoinfocs-golang` v4, `demoparser2`). Útil para auditoria e calibração, não como canal principal. |

## 2. Source 2, Workshop Tools, Hammer e `cs_script`

| Capacidade | Classificação | Evidência e limites |
|---|---|---|
| Workshop Tools gratuitas, com Hammer | Confirmada | Valve Developer Community. |
| Requisitos do Hammer | Confirmada | GPU com ray tracing **obrigatória** (RTX 2060 / RX 6600 XT / Intel Arc no mínimo), 16 GB de RAM (32 GB recomendado). Iluminação por CPU foi removida. Implica uma máquina Windows com GPU para quem fizer mapas. |
| Publicar na Steam Workshop | Confirmada | Ferramenta de publicação nas Workshop Tools. |
| Carregar mapa da Workshop no servidor (`+host_workshop_map <id>`, `+host_workshop_collection <id>`, `ds_workshop_changelevel`) | Provável (PoC) | Documentado por hospedagens e pela imagem Docker, não pela página da Valve. |
| Itens da Workshop não listados/privados no servidor dedicado | **Não confirmada** | Nenhuma fonte. Testar na T1; se falhar, mapas de treino ficam públicos na Workshop. |
| Lógica do mapa em `cs_script` (JavaScript, entidade `point_script`) | Confirmada | API oficial: ganchos `OnGunFire`, `OnBulletImpact`, `OnPlayerDamage`, `OnPlayerKill`, `OnPlayerJump`, `OnPlayerLand`, `OnPlayerConnect`, `OnPlayerActivate`, `OnRoundStart`, `OnScriptInput`; `ConnectOutput`, `EntFireAtName`, `TraceBullet`, `TraceLine`, `SetThink`/`SetNextThink`. |
| Velocidade e posição do jogador no `cs_script` | Confirmada | `GetAbsVelocity()`, `GetAbsOrigin()`, `GetEyeAngles()`. |
| Estado de botões no `cs_script` | Confirmada (por tick) | `IsInputPressed`, `WasInputJustPressed`, `WasInputJustReleased`: "em algum momento **durante o tick atual**". Resolução de um tick. |
| Interface no jogo (`custom_hud_layout`) com botões clicáveis | Confirmada | `OnCustomHudClicked`; "eventos e scripts no cliente não são suportados". |
| Persistência local do addon (`SetSaveData`/`GetSaveData`) | Confirmada | Grava em disco no servidor. Não serve como canal para a API. |
| Rede (HTTP, sockets) no `cs_script` | **Inviável/restrita** | A API documentada não tem nenhuma função de rede. |
| SteamID no `cs_script` | **Inviável/restrita** | `CSPlayerController` expõe nome, slot e time; nenhum SteamID na API documentada. |
| Estabilidade da API `cs_script` | Risco confirmado | A página está marcada como "precisa de atualização"; um patch de set/2025 removeu `Instance.OnGameEvent` e acrescentou ganchos (fonte de terceiros). |
| TypeScript | Não confirmada (oficialmente) | Suporte vem de ferramentas da comunidade; a própria wiki pergunta se `.vts` ainda funciona. |

**Conclusão**: `cs_script` serve para a **mecânica** do exercício (alvos, estímulos, HUD), mas não
pode identificar o jogador nem falar com a API. O canal confiável é o plugin C#.

## 3. Metamod:Source e CounterStrikeSharp

| Capacidade | Classificação | Evidência e limites |
|---|---|---|
| Plugins C# no servidor (CounterStrikeSharp sobre Metamod:Source 2.x dev) | Confirmada | Docs do CounterStrikeSharp. Exige Metamod 2.x **dev build** (o 1.12 estável é de Source 1) e a edição de `game/csgo/gameinfo.gi`. |
| Runtime | Confirmada | .NET 8 (README). **Atenção**: o suporte da Microsoft ao .NET 8 termina em novembro de 2026; acompanhar a migração do projeto. |
| Licença | Confirmada, a analisar | GPLv3 com exceção para plugins publicados. A licença do nosso plugin precisa de análise antes de distribuir (decisão D11). |
| Eventos do jogo (`RegisterEventHandler<EventWeaponFire>`, `EventPlayerHurt`, `EventPlayerDeath` etc.) | Confirmada | Manipuladores de eventos documentados. Quais eventos disparam e com quais campos **no CS2 atual**: conferir na T1. |
| Listeners | Confirmada | `OnTick`, `OnPlayerButtonsChanged`, `OnClientAuthorized`, `OnClientConnect`, `OnClientDisconnect`, `OnClientPutInServer`, `OnMapStart`, `OnMapEnd`, `OnPlayerTakeDamagePre/Post`, `OnEntitySpawned`, `OnCustomHudClicked`, `OnServerPreFatalShutdown`, entre outros. |
| SteamID validado pela Steam | Confirmada | `CCSPlayerController.AuthorizedSteamID`: "validado com a SteamAPI", nulo até a validação; `Listeners.OnClientAuthorized` dispara com o SteamID quando a Steam autoriza o cliente. |
| Botões e velocidade do jogador | Confirmada (por tick) | `CCSPlayerController.Buttons` (estado dos botões do pawn ativo); acesso ao schema do pawn (posição, velocidade). |
| Saídas de entidades do mapa (`trigger_multiple OnStartTouch` etc.) | Confirmada | `BasePlugin.HookEntityOutput(classname, output, handler)`. |
| Comandos de servidor e chat, timers | Confirmada | `AddCommand`, `AddTimer`. |
| Chamadas HTTP saindo do plugin | Provável (PoC) | .NET completo (`HttpClient`). Precedente: o plugin MatchZy (CounterStrikeSharp) envia eventos em JSON para `matchzy_remote_log_url`, com cabeçalho de autenticação configurável e **sem** retentativa nem deduplicação. |
| Ponte `cs_script` → plugin | Provável (PoC) | Caminhos plausíveis: `Instance.ServerCommand("cst_evt ...")` chamando um comando registrado pelo plugin, ou o plugin escutando saídas de entidades nomeadas. Nenhuma fonte testou. |
| Quebra após atualização do CS2 | **Risco confirmado** | Releases do CounterStrikeSharp de ago–set/2026 (v1.0.372 a v1.0.376) trazem correções de schema, assinaturas e offsets para as versões 1.41.6.9 a 1.41.8.4 do jogo. Atualizações do jogo também sobrescrevem `gameinfo.gi` e desligam o Metamod (guias de hospedagem e wiki da AlliedModders). |

## 4. Identidade Steam

| Capacidade | Classificação | Evidência e limites |
|---|---|---|
| "Entrar com Steam" no site (OpenID 2.0) | Confirmada | Steamworks: provedor em `https://steamcommunity.com/openid/`; Claimed ID `https://steamcommunity.com/openid/id/<steamid64>`. A verificação da resposta segue a especificação OpenID 2.0 (`check_authentication`), que a Valve não detalha. |
| Validação do jogador pelo servidor | Confirmada | Steamworks: ticket de sessão validado por `BeginAuthSession`; a identidade só é confirmada quando chega `ValidateAuthTicketResponse_t`, que pode atrasar. O CounterStrikeSharp expõe o resultado como `AuthorizedSteamID`. |
| Steam Web API (perfil, posse do jogo) | Confirmada | Termos: limite de 100.000 chamadas/dia, política de privacidade obrigatória, chave confidencial, proibido sugerir afiliação com a Valve. |
| Verificar posse do CS2 pelo site | Restrita | `CheckAppOwnership` é de chave de publisher. Desnecessário: quem entra no servidor já passou pela Steam. |

## 5. Políticas e restrições externas

| Tema | Classificação | O que vale |
|---|---|---|
| Itens e ranques falsos | Confirmada (proibido) | Suporte Steam "CS2 - GSLT Bans": proibido simular itens não possuídos (skins, facas, inclusive só para o próprio jogador), falsificar nível competitivo, ranque de perfil ou moeda no placar, ou interferir no acesso ao inventário. Banimento é por **conta**, permanente, inegociável, com cooldown de partidas para o operador. |
| Placar personalizado | Confirmada (permitido) | Mudar pontos e nomes no placar é aceito (exemplo de servidores de surf). |
| Armas padrão por menu | Confirmada (permitido) | Dar armas e granadas padrão por mod é aceito. |
| Cobrar acesso a servidores de treino | **Não confirmada** | Não achamos regra da Valve proibindo nem autorizando; há concorrente comercial ativo (Refrag). Requer análise jurídica (D10). |
| Marca "CS"/"Counter-Strike" | Não confirmada | Os termos da Steam Web API proíbem sugerir afiliação com a Valve. O nome "CS Tactical" merece análise de marca (D10). |
| Ferramentas que dão vantagem competitiva | Confirmada (proibido) | Termos da Steam Web API proíbem tecnologia que dê "vantagem competitiva injusta" em multijogador. O módulo não terá nada no cliente do jogador. |

## 6. Privacidade e menores

| Tema | Classificação | O que vale |
|---|---|---|
| ECA Digital (Lei 15.211/2025) | Confirmada (fontes jurídicas) | Em vigor desde 17/03/2026; alcança produtos "direcionados ou de acesso provável" por menores, inclusive jogos online e empresas no exterior: verificação de idade, controle parental, configurações mais protetivas por padrão. Regulamentação detalhada: conferir na ANPD. |
| LGPD, art. 14 | Confirmada | Dados de crianças e adolescentes no melhor interesse; consentimento de um dos pais para crianças. |
| Idade mínima da Steam | Provável | Assinatura Steam exige 13+ (conferir o Steam Subscriber Agreement vigente). |
| Classificação indicativa do CS2 no Brasil | **Não confirmada** | Não achamos a classificação atual no ClassInd. Conferir antes de definir a política de idade (D9). |

## Hipóteses que dependem de experimento (entrada da T1)

| # | Hipótese |
|---|---|
| H1 | Os eventos `weapon_fire`, `player_hurt`, `player_death` e `bullet_impact` disparam no CS2 atual; com quais campos e em qual ordem dentro de um tick |
| H2 | A velocidade horizontal lida do pawn no tick do tiro é a mesma que o jogo usa para a imprecisão (o CS2 processa entradas em "subtick"; o servidor só mostra o estado no tick) |
| H3 | Latência entre o estímulo criado no servidor e a resposta observada, por faixa de ping |
| H4 | Carregar mapa não listado da Workshop no servidor dedicado |
| H5 | CPU e memória por instância ociosa e com um jogador ativo; instâncias por máquina |
| H6 | Tempo entre um patch do CS2 e a volta do Metamod/CounterStrikeSharp (acompanhar alguns patches) |
| H7 | Ponte `cs_script` → plugin |
| H8 | Ping do jogador acessível ao plugin (campo do controller) para qualificar métricas de tempo |

## Fontes (consultadas em 2026-10-17)

Oficiais (Valve/Steam):

- [Counter-Strike 2 Dedicated Servers](https://developer.valvesoftware.com/wiki/Counter-Strike_2/Dedicated_Servers) — Valve Developer Community (página marcada como "precisa de atualização").
- [CS2 Workshop Tools](https://developer.valvesoftware.com/wiki/Counter-Strike_2_Workshop_Tools) e [System Requirements](https://developer.valvesoftware.com/wiki/Counter-Strike_2_Workshop_Tools/System_Requirements).
- [cs_script Scripting API](https://developer.valvesoftware.com/wiki/Counter-Strike_2_Workshop_Tools/Scripting_API) e [Scripting](https://developer.valvesoftware.com/wiki/Counter-Strike_2_Workshop_Tools/Scripting).
- [Steamworks — User Authentication and Ownership](https://partner.steamgames.com/doc/features/auth) (OpenID e tickets de sessão).
- [Steam Web API Terms of Use](https://steamcommunity.com/dev/apiterms) (atualizado em julho de 2010).
- [Steam Support — CS2 GSLT Bans](https://help.steampowered.com/en/faqs/view/07AF-502E-A104-BD4B).
- [Game Server Operation Guidelines (CS:GO, arquivo de 2023)](https://web.archive.org/web/20231207223108/https://blog.counter-strike.net/index.php/server_guidelines/).

Projetos do ecossistema (documentação própria):

- [CounterStrikeSharp — Getting Started](https://docs.cssharp.dev/docs/guides/getting-started.html), [Referencing Players](https://docs.cssharp.dev/docs/guides/referencing-players.html), [CCSPlayerController](https://docs.cssharp.dev/api/CounterStrikeSharp.API.Core.CCSPlayerController.html), [Listeners](https://docs.cssharp.dev/api/CounterStrikeSharp.API.Core.Listeners.html), [BasePlugin](https://docs.cssharp.dev/api/CounterStrikeSharp.API.Core.BasePlugin.html).
- [CounterStrikeSharp no GitHub](https://github.com/roflmuffin/CounterStrikeSharp) e [releases](https://github.com/roflmuffin/CounterStrikeSharp/releases).
- [MatchZy — Events and forwards](https://shobhit-pathak.github.io/MatchZy/events_and_forwards/).
- [joedwards32/CS2 (Docker)](https://github.com/joedwards32/CS2).
- [demoinfocs-golang](https://github.com/markus-wa/demoinfocs-golang).

Terceiros (usados só como indício, nunca como confirmação):

- [Installing Metamod:Source — AlliedModders](https://wiki.alliedmods.net/Installing_SourceMM) e guias de hospedagem sobre `gameinfo.gi` ([GameServerKings](https://www.gameserverkings.com/knowledge-base/counter-strike-2/how-to-install-plugins.md)).
- [Hostinger — Workshop maps in CS2](https://www.hostinger.com/tutorials/how-to-play-workshop-maps-in-cs2) (`host_workshop_map`).
- [Refrag — Introducing Sessions (28/05/2026)](https://refrag.gg/blog/introducing-sessions-a-new-era-of-cs2-training-on-refrag).
- [Machado Meyer — ECA Digital entra em vigor em 17/03/2026](https://www.machadomeyer.com.br/pt/inteligencia-juridica/publicacoes-ij/direito-digital/estatuto-digital-da-crianca-e-do-adolescente-lei-n-15-211-2025-entra-em-vigor-em-17-de-marco-de-2026) e [Mattos Filho](https://www.mattosfilho.com.br/unico/eca-digital-sancionada-lei/).
- Preços e requisitos de hospedagem: ver as fontes de [infraestrutura.md](infraestrutura.md#fontes).
