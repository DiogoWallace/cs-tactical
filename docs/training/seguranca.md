# Segurança e privacidade do Training

> Planejamento (T0, 2026-10-17). Complementa [autenticacao.md](../architecture/autenticacao.md),
> [autorizacao.md](../architecture/autorizacao.md), [seguranca-http.md](../architecture/seguranca-http.md)
> e os [bloqueios de produção](../deploy/bloqueios-de-producao.md). **A produção continua bloqueada
> por P1–P4 e G1**; a infraestrutura atual não está pronta para hospedar servidor de jogo público.

## Ativos

| Ativo | Por que importa |
|---|---|
| Vínculo conta ↔ SteamID | Base de toda atribuição de resultado |
| Credenciais dos servidores de jogo | Permitem enviar resultados em nome de qualquer sessão alocada naquele servidor |
| Resultados e histórico | Dado pessoal; base de recomendações e, no futuro, de treinadores |
| Servidores de jogo | Expostos à internet (UDP), rodam código de terceiros (Metamod, CounterStrikeSharp) |
| Conta Steam que gera os GSLT | Banimento é por conta e permanente |
| API e banco | Já protegidos pela Fase 1; ganham uma superfície nova (ingestão) |

## Análise de ameaças

| # | Ameaça | Cenário | Controles | Risco residual |
|---|---|---|---|---|
| A1 | Servidor de jogo exposto | Exploração do jogo ou do Metamod/CounterStrikeSharp leva a execução de código no host | Host dedicado, separado da API/banco; usuário sem privilégios; firewall (entrada só UDP do jogo + SSH por chave restrito por IP/VPN; saída só Steam e API); atualizações automáticas do SO; nenhum segredo além da credencial do próprio servidor e do GSLT | Médio: depende de software de terceiros |
| A2 | Plugins de terceiros | Plugin malicioso ou comprometido na cadeia de fornecimento | Só Metamod + CounterStrikeSharp + plugin próprio; versões fixadas com *checksum*; download só dos repositórios oficiais; revisão das notas de cada versão; build do plugin na CI | Médio |
| A3 | Eventos falsificados | Alguém sem credencial envia "resultados perfeitos" para a API | HMAC por servidor; sessão precisa estar alocada **naquele** servidor; participante igual ao da reserva; schema fechado; recálculo na API | Baixo para quem não tem credencial. **Não cobre servidor comprometido** (A13) |
| A4 | Associação indevida de identidade | Vincular o SteamID de outra pessoa; usar resultados de terceiros | SteamID só por OpenID verificado com `check_authentication` e por `AuthorizedSteamID`; `state` amarrado à sessão do site; SteamID único por conta; carência para troca; claim só do SteamID da reserva | Baixo; conta Steam compartilhada não é detectável |
| A5 | Acesso não autorizado a sessões | Ler resultados de outro jogador; entrar na sessão de outro | Policies por posse (como `Learning`); 404 para recurso alheio; servidor desconecta quem não é o SteamID da reserva | Baixo |
| A6 | Vazamento de credenciais | Credencial do servidor copiada do disco ou de logs | Arquivo legível só pelo processo; nunca em log; rotação (duas ativas); revogação imediata; credencial com escopo só nas rotas `agent`; `last_used_at` e IPs de origem monitorados | Médio |
| A7 | Ataque de repetição | Reenvio de lote capturado | TLS; timestamp ±120 s; nonce único no Redis; `batch_id` e `(session_id, seq)` únicos | Baixo |
| A8 | Negação de serviço | UDP contra o servidor de jogo; flood na ingestão; reservas em massa | Proteção DDoS do provedor; endereço só mostrado a quem reservou; limites por credencial e por usuário; uma sessão aberta por usuário; reservas expiram; a borda limita por IP | Médio (UDP) |
| A9 | Abuso da API de telemetria | Servidor legítimo com plugin defeituoso inunda a API | Limites de tamanho, eventos e requisições; quarentena; desativar servidor | Baixo |
| A10 | Retenção excessiva | Histórico de movimentação guardado sem necessidade | Coleta mínima (janelas de evidência, sem fluxo por tick, sem IP); brutos por 90 dias; exclusão em cascata com a conta | Baixo |
| A11 | Vazamento de dados de jogadores | Exposição de SteamID, padrões de horário, desempenho | Resultados privados por padrão, sem ranking público no MVP; `Resources` com lista fechada de campos; SteamID nunca exposto a outros usuários | Baixo |
| A12 | Atualização incompatível do CS2 | Plugin quebra ou mede errado depois de um patch | Heartbeat com build do CS2; servidor de *staging*; desativação automática se o plugin não responder; marca de comparabilidade; testes de calibração após patch | Alto (frequência), impacto contido |
| A13 | Comprometimento do servidor de jogo | Atacante controla o plugin (ou o segredo) e assina telemetria falsa das sessões alocadas ali; a assinatura é válida | A assinatura não protege este caso. Controles: escopo da credencial limitado ao servidor; estado de confiabilidade separado da autenticação (resultado `suspect` com valores implausíveis, divergência plugin × recálculo, padrões estatísticos anômalos por servidor); auditoria por amostragem com demo do servidor (quando autorizada); revogar e reconstruir o host a partir de imagem; resultados do período reclassificados para `suspect` em bloco | Médio: dado plausível e bem forjado pode passar como `validated` |
| A14 | Banimento de GSLT | Mod que simule itens ou ranques (ex.: skins, facas, "nível competitivo") | Lista de proibições da Valve no checklist de revisão do plugin; nenhum recurso de inventário; GSLT de uma conta dedicada da empresa | Baixo, se respeitado |
| A15 | SSRF/abuso via comandos | Chat do jogo (`!cst ...`) usado para injetar comandos | Comandos do plugin com lista fechada e parâmetros validados; nenhuma chamada a URL vinda do jogador | Baixo |

## Limite da autenticação de origem

A assinatura HMAC de cada requisição **autentica a origem declarada** (quem tem o segredo daquele
servidor), a integridade do corpo e a ausência de repetição. Ela **não impede telemetria falsificada
por um servidor comprometido**: quem controla o servidor controla o segredo e o plugin. Em
consequência:

- "assinado" nunca significa "confiável": a confiabilidade do resultado é avaliada à parte, pelos
  estados `validated`, `incomplete`, `suspect` e `invalid` ([metricas.md](metricas.md#confiabilidade-dos-resultados));
- resultados de Training não devem servir de base para nada com valor externo (prêmios, seleção de
  jogadores, certificados) sem uma verificação adicional fora do servidor de jogo;
- o risco é tratado pela proteção do host (A1, A2), pela detecção (A13) e pela capacidade de
  reclassificar em bloco.

## Controles da ingestão no Laravel

- Guard próprio `training-agent` (HMAC), separado do Sanctum; o `RouteAuthorizationTest` ganha a
  categoria "rotas de máquina".
- Entrada pela borda em host próprio, com os mesmos requisitos de IP real da ADR 0009 (P1–P3); o
  limite de requisições é por credencial, não por IP.
- Erros sem detalhes internos (padrão da API); 404 para sessão alheia.
- Testes em matriz: assinatura válida/inválida, timestamp fora da janela, nonce repetido, credencial
  revogada, sessão de outro servidor, participante diferente, lote duplicado, limites.

## Privacidade

| Dado | Finalidade | Base legal provável (LGPD) | Retenção |
|---|---|---|---|
| SteamID64 | Identificar o jogador no servidor | Execução do contrato (art. 7º, V) | Enquanto vinculado |
| Eventos de jogo | Calcular métricas | Execução do contrato | 90 dias |
| Tentativas, métricas | Evolução e recomendações | Execução do contrato | Enquanto a conta existir |
| IP do jogador | Necessário ao jogo, **não** coletado pela aplicação | — | — |
| Logs técnicos | Segurança e diagnóstico | Legítimo interesse | 14–30 dias |

Requisitos:

- Política de privacidade atualizada antes da T3 (exigida também pelos termos da Steam Web API, que
  pedem política publicada e informação ao usuário sobre os dados da Steam guardados e o país de
  armazenamento).
- Exportação e exclusão dos dados de treino junto com os da conta.
- Nenhum compartilhamento com equipes ou treinadores sem atribuição explícita (Fase 4).

### Menores de idade

- O **ECA Digital** (Lei 15.211/2025, em vigor desde 17/03/2026) alcança serviços de acesso provável
  por menores, inclusive jogos online: verificação de idade efetiva, ferramentas de supervisão
  parental e configurações mais protetivas por padrão. A **LGPD (art. 14)** exige tratar dados de
  crianças e adolescentes no melhor interesse deles, com consentimento de um dos pais para crianças.
- A Steam exige 13 anos ou mais para ter conta (conferir o acordo vigente); a classificação
  indicativa do CS2 no Brasil **não foi confirmada** nesta pesquisa.
- Proposta (D9, depende de parecer jurídico): idade mínima do Training igual à classificação
  indicativa do CS2; data de nascimento declarada no cadastro, sem perfil público nem ranking por
  padrão; para adolescentes, configurações mais restritivas (sem compartilhamento com equipes até
  haver controle parental). Sem esse parecer, **o Training não entra em produção**.

## Questões jurídicas para análise (D10)

1. Cobrar pelo acesso a servidores de treino de um jogo da Valve (não achamos regra explícita; há
   concorrente comercial).
2. Uso de "CS" no nome e na marca (os termos da Steam Web API proíbem sugerir afiliação com a Valve).
3. Licença GPLv3 do CounterStrikeSharp e a distribuição do plugin próprio (D11).
4. Redistribuição de mapas derivados de mapas oficiais em cenários táticos.
