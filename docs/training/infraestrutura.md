# Infraestrutura dos servidores de treino

> Planejamento (T0, 2026-10-17). **Nada contratado, nenhum serviço novo no `compose.yaml`.** Preços
> são estimativas de fontes públicas da data, sem impostos nem câmbio; pedir cotação antes de decidir.

## Requisitos de base

| Item | Valor | Origem |
|---|---|---|
| Sistema | Linux com glibc ≥ 2.31 (Ubuntu 20.04+, Debian 11+, EL9+) | Valve (confirmado) |
| Disco | ~60–65 GB por cópia do jogo (+ mapas, logs, buffer do plugin) | Valve (confirmado) |
| Memória | mínimo 2 GB por instância; real a medir | Valve (mínimo); medir na T1 |
| CPU | x86-64-v2; desempenho por núcleo importa mais que número de núcleos | Valve (mínimo); guias de hospedagem (indício) |
| Rede | UDP 27015 (jogo) por instância, +1 porta por instância; TCP de saída para Steam e API | Valve/SRCDS (confirmado) |
| Latência | servidores no Brasil para jogadores brasileiros; Europa→Brasil fica na casa de 200 ms (estimativa) | indício; medir |
| Steam | GSLT por instância pública, conta Steam com telefone qualificado | Valve (confirmado) |

Cada instância atende **um jogador** no MVP (D5): sessões isoladas, métricas sem interferência de
outros jogadores. Alternativa a avaliar depois: um mapa com várias arenas isoladas por instância
(mais barato, mais complexo e com risco de interferência no desempenho medido).

## Contêiner ou processo direto

| Opção | Prós | Contras |
|---|---|---|
| Contêiner (ex.: imagem comunitária `joedwards32/cs2`, citada pela Valve) | Reprodutível, isolamento de processo, fácil de recriar | Imagem/volume de 60 GB; atualização reinicia; ajuste de `gameinfo.gi` para o Metamod em todo update (script de entrada); rede *host* recomendada para UDP |
| Processo com `systemd` por instância, arquivos do jogo compartilhados | Menos camadas, uma cópia do jogo para N instâncias (ferramenta "CS2 Multiserver" citada pela Valve) | Isolamento depende de usuários Linux e permissões |

Decisão na T1, com medição. Em qualquer caso: usuário sem privilégios, sem acesso ao Docker do host,
`Restart=on-failure`, limites de CPU/memória por instância.

## Cenário 1 — Laboratório local (T1/T2)

| Item | Proposta |
|---|---|
| Onde | O próprio PC de desenvolvimento: cliente CS2 no Windows; servidor dedicado no Ubuntu do WSL2 (ou numa máquina Linux da rede local) |
| Separação | Diretório e Compose **próprios** do laboratório (ex.: `lab/cs2/`), fora da stack da aplicação; nunca no `compose.yaml` atual |
| Rede | Cliente → servidor pela rede local/WSL (encaminhamento UDP entre Windows e WSL2: **validar**, modo de rede *mirrored* é candidato); plugin → API em `http://localhost:8000` (mesma máquina WSL) |
| Identidade | Com GSLT e Steam (para validar `AuthorizedSteamID`) e também em modo `sv_lan 1` (para ver o comportamento sem autenticação) |
| Recursos | ~65 GB de disco no WSL (o disco virtual cresce e não encolhe sozinho), 2–4 GB de RAM |
| Custo | ~R$ 0 além da máquina existente |
| Uso | Verificar hipóteses H1–H8, medir CPU/RAM, gravar demos para calibração |

## Cenário 2 — MVP com um servidor dedicado (após T6, em produção só depois de P1–P4 e G1)

| Item | Proposta |
|---|---|
| Máquina | 1 host em São Paulo, separado da API e do banco |
| Opção A: VPS com vCPU dedicada (4 vCPU, 8 GB, 160 GB NVMe) | Capacidade estimada: 2–3 sessões simultâneas (a medir). Custo estimado: **US$ 60–120/mês** (faixa típica de mercado; sem cotação) |
| Opção B: servidor dedicado (ex.: Xeon E-2388G, 32 GB, 2×480 GB SSD) | Capacidade estimada: 6–8 sessões simultâneas (a medir). Custo publicado por um provedor de São Paulo: **a partir de US$ 180/mês** (artigo de mai/2026) |
| Não recomendado para jogadores | Europa (ex.: Hetzner AX, ~€50–80/mês, preço não confirmado na página da Hetzner): bom para laboratório ou CI, latência alta para o Brasil |
| Itens sem custo direto | GSLT, Steam Web API, Workshop, certificados TLS (Let's Encrypt) |
| Custos indiretos | Monitoramento/logs (auto-hospedado ou plano gratuito), horas de operação após cada patch do CS2 |

Operação:

- **Atualização do CS2**: detecção do novo build (SteamCMD ou heartbeat com clientes recusando) →
  servidor de *staging* atualiza primeiro → teste automático (Metamod carregou, `meta list`, plugin
  responde a um comando de autoteste e envia heartbeat) → servidores `draining` → atualização →
  `active`. Se o CounterStrikeSharp quebrar, os servidores ficam `disabled` e o site mostra "treinos
  indisponíveis" até a correção. **Não dá para segurar a versão antiga**: o cliente atualizado não
  entra.
- **Plugins e Metamod**: só versões oficiais, com *checksum* conferido e fixado; nada de plugins de
  terceiros além do Metamod e do CounterStrikeSharp.
- **Mapas**: atualizados em janela, conferidos pelo hash.
- **Logs e monitoramento**: console do servidor e plugin → journald → coletor; métricas de CPU,
  memória, duração dos ticks (`server_perf`), heartbeat, taxa de rejeição da ingestão, atraso da fila.
  Alertas: servidor sem heartbeat > 2 min, rejeições > 5%, buffer do plugin crescendo.
- **Reinício**: supervisor reinicia a instância; a API marca sessões `interrupted`.
- **Disponibilidade**: o Training é um recurso degradável; a indisponibilidade não afeta Academy nem
  conta. Meta inicial: sem SLA, com página de estado.

## Cenário 3 — Expansão para várias sessões simultâneas

| Item | Proposta |
|---|---|
| Hosts | N máquinas por região (São Paulo primeiro), dimensionadas para o pico de sessões simultâneas |
| Alocação | API escolhe a instância livre (lock no Redis); cada host roda um **agente** simples que recebe o estado desejado (quantas instâncias, quais mapas, versões) e cuida do ciclo de vida local |
| Orquestração | Agente próprio até ~5 hosts; acima disso, avaliar Agones (Kubernetes) ou Nomad — benefícios reais só com frota grande |
| Arquivos do jogo | Uma cópia por host compartilhada pelas instâncias (somente leitura) |
| Ingestão | Mesma API; se passar de ~100 mil sessões/mês, serviço de ingestão separado e armazenamento colunar ([modelagem.md](modelagem.md#volume)) |
| Custo | Por sessão simultânea de pico: ~US$ 20–40/mês com dedicados bem ocupados (estimativa a partir do cenário 2). Ex.: 30 simultâneas no pico ≈ 4–5 dedicados ≈ **US$ 750–1.250/mês** |
| Rede | Proteção contra DDoS do provedor é critério de escolha (servidores de jogo UDP são alvo comum) |

## Fontes

- [Valve — CS2 Dedicated Servers](https://developer.valvesoftware.com/wiki/Counter-Strike_2/Dedicated_Servers) (requisitos, SteamCMD, GSLT, Docker, CS2 Multiserver), consultada em 2026-10-17.
- [joedwards32/CS2](https://github.com/joedwards32/CS2) (2 CPUs, 2 GiB, 60 GB; hibernação desligada por causar falhas).
- [Valebyte — Best dedicated servers in São Paulo 2026](https://valebyte.com/en/blog/best-dedicated-servers-in-sao-paulo-2026-review-and-prices/) (14/05/2026; conteúdo de provedor, preços aproximados e inconsistentes entre tabela e texto).
- [Hetzner — AX servers](https://www.hetzner.com/dedicated-rootserver/matrix-ax/) (preço não exibido na consulta; valores citados vêm de terceiros e podem estar desatualizados).
- [Winternode — How much RAM does a CS2 server need](https://winternode.com/blog/cs2/how-much-ram) (indício de 4–6 GB para servidores com mais jogadores).
