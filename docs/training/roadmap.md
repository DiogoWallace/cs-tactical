# Roadmap do Training e prova de conceito

> Planejamento (T0, 2026-10-17). Nenhuma etapa além da T0 foi iniciada. A Fase 2 (Academy) continua
> sendo a prioridade. **Autorizado hoje (D7): só pesquisa e documentação.** Nenhuma PoC, instalação
> (servidor CS2, Metamod, CounterStrikeSharp, SteamCMD) ou desenvolvimento está autorizado; cada
> etapa a partir da T1 depende de autorização explícita.

## Requisito de entrada: medição antes da implementação

A **PoC de medição (T1 + T2) é pré-requisito** para qualquer implementação completa (T3 em diante,
exceto documentação). Ela precisa responder, com dados do laboratório, se as métricas centrais
(velocidade no tiro, ticks até parar, contagem de tiros) são observáveis com a precisão prometida.
Se a resposta for não, o escopo do Training é revisto antes de escrever código na aplicação.
Até lá, a ADR do Training permanece **Proposta**.

## Ordem e dependências

```
T0 pesquisa/ADR ─► T1 laboratório CS2 ─► T2 PoC de medição ══ portão ══╦═► T3 vínculo Steam (Identity) ─┐
                                                                       ║                                 ├─► T5 sessões ─► T6 1º exercício ─► T7 painel
                                                                       ╚═► T4 ingestão ─────────────────┘                                      │
                                                                           T8 mapas próprios ◄────────────────────────────────────────────────┘
                                                                           T9 integração Academy (após T7 e fim da Fase 2)
                                                                           T10 operação/produção (após P1–P4, G1, D9, D10)
```

Diferenças em relação à sugestão inicial: a **medição** (T1 + T2) vem antes de identidade e
persistência, porque se as métricas centrais não forem confiáveis o resto não se justifica. Depois do
portão, T3 (vínculo Steam) e T4 (ingestão) não dependem uma da outra e podem andar em paralelo.

## Etapas

| Etapa | Escopo | Depende de | Testes | Concluída quando | Riscos |
|---|---|---|---|---|---|
| **T0** Pesquisa e ADR | Esta documentação e a ADR (Proposta) | — | Revisão; links válidos | Documentação revisada; decisões D1–D14 respondidas (a ADR só vira Aceita depois da T2) | Fontes desatualizadas |
| **T0.1** Fontes de telemetria (2026-10-23) | Comparar plugin, GSI e demos ([fontes-de-telemetria.md](fontes-de-telemetria.md)); experimentos E1 (GSI) e E2 (demo), cada um com confirmação | T0 | Experimentos registrados no documento | Decisões F1–F5 respondidas | Fontes de terceiros sobre o GSI; disco para o laboratório |
| **T1** Laboratório CS2 (**requer autorização**) | Servidor dedicado no WSL2/Linux local, Metamod, CounterStrikeSharp, plugin "olá" que registra eventos em arquivo; com e sem Steam; gravação de demo | T0 | Checklist H1–H8 de [viabilidade-cs2.md](viabilidade-cs2.md#hipóteses-que-dependem-de-experimento-entrada-da-t1) | Relatório da T1 com cada hipótese reclassificada e consumo de recursos medido | Encaminhamento UDP no WSL2; espaço em disco |
| **T2** PoC de medição (**requer autorização**; portão para T3+) | Counter-strafe só no plugin (log local); parser de demo para comparar velocidade no tick do tiro; calibração do limiar por arma | T1 | Comparação plugin × demo em ≥ 3 sessões | Diferença plugin × demo dentro da tolerância (ver PoC) ou decisão documentada de mudar a métrica | H2 falhar (velocidade no tick ≠ usada pelo jogo) |
| **T3** Vínculo Steam | OpenID no `Identity`, `user_steam_accounts`, tela em `/painel/conta`, política de privacidade | T2 (portão de medição); independe da T4 | Pest: resposta adulterada, `return_to` errado, replay do nonce, SteamID já vinculado; E2E com Steam simulada | Vínculo e desvínculo funcionando com testes de segurança | Mudanças no OpenID da Steam |
| **T4** Ingestão | Módulo `Training`, servidores e credenciais, guard HMAC, `heartbeat`/`events`/`summary`/`end`, eventos brutos particionados, idempotência, limites | T2 (portão de medição) | Pest com cliente assinado falso; contrato por *fixtures* JSON compartilhadas com o plugin (como os blocos da Academy) | Matriz de segurança da ingestão verde; carga sintética de 100 sessões | Volume maior que o estimado |
| **T5** Sessões | Reserva, alocação, claim, ciclo de vida, expiração, reconexão, revogação; plugin integrado | T4 | Pest (estados e corridas com lock); testes do Engine em xUnit; teste manual no laboratório | Todos os casos-limite de [arquitetura.md](arquitetura.md#casos-limite) cobertos | Corridas de alocação |
| **T6** Primeiro exercício | Counter-strafe completo: definição versionada, `training:import`, calculadora v1, validade | T5 | Calculadora com casos fixos; recálculo idempotente; laboratório | **Critérios da PoC atendidos** | Limiar ainda instável |
| **T7** Painel | Telas de exercícios, reserva, sessão, resultado e evolução (Next.js), acessibilidade | T6 | Vitest, Playwright com resultados semeados, axe nos dois temas | Jogador vê resultado e evolução de 3 sessões | — |
| **T8** Mapas próprios | Contrato de entidades v1, mapa `cst_strafe` e um circuito no Hammer, publicação na Workshop, hash conferido | T6 | Laboratório; verificação de contrato no carregamento | Dois exercícios rodando em mapas próprios | H4 (itens não listados); máquina com GPU RT |
| **T9** Integração Academy | Taxonomia de habilidades, links aula ↔ exercício, regras de recomendação | T7 e fim da Fase 2 | Testes das regras; E2E aula → exercício | Recomendações explicadas na interface | Acoplamento entre módulos |
| **T10** Operação e produção | Host em São Paulo, borda `ingest`, runbook de atualização do CS2, monitoramento, revisão de segurança e privacidade, teste de carga | T7, P1–P4, G1, D9, D10 | Sondagem de segurança (como a da Fase 1); simulação de patch | Auditoria de fechamento | Custo, quebras por patch |

## Prova de conceito

Duas partes, ambas **não autorizadas** hoje (D7):

1. **PoC de medição (T1 + T2), portão obrigatório**: laboratório com servidor dedicado, Metamod,
   CounterStrikeSharp e um plugin que só grava eventos em arquivo local; comparação com a demo do
   servidor. Sem API, sem banco, sem tela. Critérios 5 e 6 abaixo, mais o relatório das hipóteses
   H1–H8. Se falhar, o escopo é revisto antes de qualquer código na aplicação.
2. **PoC ponta a ponta (até T6)**, só depois do portão: **1 servidor dedicado (laboratório) + 1 plugin
   + 1 exercício de counter-strafe + 1 sessão de telemetria**, num mapa existente, com a API em
   ambiente de desenvolvimento. Todos os critérios abaixo.

| # | Demonstração | Critério de aceite mensurável |
|---|---|---|
| 1 | Jogador estabelece sessão | Reserva criada em < 1 s; prazo de 10 min; segunda reserva aberta → 409 |
| 2 | Vínculo seguro com a identidade do jogo | SteamID só via OpenID verificado; 100% de rejeição nos testes de resposta adulterada, `return_to` trocado e nonce repetido |
| 3 | Servidor reconhece o jogador autorizado | Claim em ≤ 5 s após `OnClientAuthorized`; SteamID sem reserva desconectado em ≤ 2 s; em `sv_lan 1` nenhum resultado oficial |
| 4 | Exercício inicia | Tentativas começam pela regra da definição v1 (zona) em 100% dos casos de teste |
| 5 | Plugin observa eventos | Contagem de tiros do plugin = contagem na demo, em 3 sessões de ≥ 50 tiros |
| 6 | Resultado verificável | Velocidade no tick do tiro: plugin × demo iguais (±0,1 u/s) em ≥ 95% dos tiros; divergências explicadas |
| 7 | Envio à API | Com a API parada por 5 min no meio da sessão, 100% dos eventos chegam depois, 0 duplicatas |
| 8 | Validação e persistência | Lote reenviado → 200 sem gravar; corpo alterado → 401; timestamp a 300 s → 401; evento de tipo desconhecido → 422 |
| 9 | Frontend mostra o resultado | Resultado visível em ≤ 10 s após o fim da sessão |
| 10 | Evolução | Três sessões `validated` aparecem como três pontos na mesma chave de comparabilidade; uma sessão com versão de exercício não comparável aparece em série separada |
| 11 | Confiabilidade | Sessão com lacuna → `incomplete`; lote com valor implausível ou troca de versão → `suspect`; `sv_cheats 1` → `invalid`; nenhuma delas entra em tendência ou recorde |
| 12 | Versões | Toda sessão tem build do CS2, plugin, Engine, mapa e exercício preenchidos; todo resultado tem a calculadora |
| — | Falha do servidor | Instância derrubada no meio: sessão `interrupted` em ≤ 90 s; nenhuma alocação nova nela |

### O que dá para simular e o que exige CS2 real

| Componente | Teste automatizado | Exige servidor CS2 real |
|---|---|---|
| Training Engine (máquina de estados, tentativas, janelas) | Sim: núcleo em C# separado da API do CounterStrikeSharp, com eventos sintéticos (xUnit) | — |
| Transporte do plugin (assinatura, buffer, *backoff*) | Sim, contra servidor HTTP falso | — |
| Ingestão, sessões, cálculo (Laravel) | Sim (Pest), com cliente assinado falso e *fixtures* compartilhadas | — |
| Vínculo Steam | Sim, com a Steam simulada (respostas OpenID gravadas) | Uma verificação manual com a Steam real |
| Painel (Next.js) | Sim (Vitest/Playwright com dados semeados) | — |
| Autenticação Steam no servidor (`AuthorizedSteamID`) | Não | Sim |
| Disparo, campos e ordem dos eventos do jogo | Não | Sim |
| Velocidade e botões por tick, calibração | Não | Sim (+ demo) |
| Gatilhos e entidades do mapa | Não | Sim |
| Quebra por atualização do CS2 | Não | Sim (acompanhar patches) |
| Carga e recursos por instância | Parcial (carga sintética na API) | Sim |

A CI não terá servidor CS2: o laboratório gera *fixtures* reais (lotes de eventos gravados) que
passam a ser usadas nos testes do Laravel e do Engine.

## Decisões

Situação em 2026-10-17, depois da revisão do responsável.

### Resumo

| # | Tema | Situação |
|---|---|---|
| D1 | Nome do módulo | **Aprovada conceitualmente**: `Training` = prática no CS2; `Coaching` proposto para a Fase 4. Documentos de outras fases **não** são alterados agora |
| D2 | Onde fica a lógica do exercício | Pendente (recomendação abaixo) |
| D3 | Autenticação máquina a máquina | Pendente |
| D4 | Retenção | Pendente |
| D5 | Instância por jogador ou arenas | Pendente |
| D6 | Hospedagem do MVP | Pendente |
| D7 | Quando começar | **Decidida**: pesquisa e documentação em paralelo à Academy; nenhuma PoC, instalação ou desenvolvimento autorizado |
| D8 | Visibilidade dos resultados | Pendente |
| D9 | Política de idade | **Pendente de validação jurídica** |
| D10 | Cobrança, marca "CS", termos da Valve | **Pendente de validação jurídica** |
| D11 | Licença do plugin | **Pendente de validação jurídica** |
| D12 | Número da ADR | **Confirmada: 0021** (ver abaixo) |
| D13 | Visibilidade dos mapas na Workshop | Pendente (depende de H4) |
| D14 | E-mail confirmado para vincular Steam | Pendente |

### D1 — Nome do módulo

Aprovada conceitualmente. `Training` designa os treinamentos dentro do CS2; `Coaching` é a **proposta**
de nome para agenda e atribuições de equipe (Fase 4). [visao-geral.md](../architecture/visao-geral.md)
e [modelo-conceitual.md](../database/modelo-conceitual.md) continuam como estão até uma aprovação
formal, feita no PR dessas fases.

### D2 — Onde fica a lógica do exercício

- **Recomendação**: Training Engine no plugin C#, exercícios em definições versionadas (repositório →
  API → plugin); `cs_script` só para mecânica e HUD do mapa, nunca como fonte única de métrica.
- **Alternativas**: tudo no `cs_script` (sem rede e sem SteamID: inviável sozinho); tudo codificado no
  plugin (cada ajuste exige novo build e nova homologação).
- **Impacto**: um só lugar com lógica testável fora do jogo; ajuste de parâmetros sem novo plugin;
  exige um schema de definição e a verificação de compatibilidade Engine × definição. Se a T1 mostrar
  que a ponte `cs_script` → plugin não funciona (H7), nada muda nesta decisão.

### D3 — Autenticação máquina a máquina

- **Recomendação**: HMAC-SHA256 por requisição, credencial por servidor, sobre TLS; mTLS só na expansão.
- **Alternativas**: token Bearer (não protege o corpo nem impede repetição); mTLS desde o início
  (emissão e rotação de certificados, borda repassando identidade).
- **Impacto**: autentica a origem declarada e impede alteração e repetição; **não** impede telemetria
  falsificada por servidor comprometido (tratado pela confiabilidade dos resultados). Exige segredo
  cifrado no banco, relógio sincronizado (NTP) nos servidores e um guard novo no Laravel.

### D4 — Retenção

- **Recomendação**: eventos brutos e lotes por 90 dias (partições mensais); tentativas, resultados e
  métricas enquanto a conta existir; demos não gravadas em produção no MVP (só no laboratório, quando
  autorizado); se passarem a ser gravadas, 14 dias fora do banco.
- **Alternativas**: guardar brutos para sempre (custo e exposição maiores); não guardar brutos
  (impossível recalcular ou auditar).
- **Impacto**: recálculo possível por 90 dias; depois disso, uma correção de fórmula só vale para
  sessões recentes. Volume previsível (~1 GB por mil sessões/mês no total retido).

### D5 — Instância por jogador ou arenas

- **Recomendação**: uma instância do servidor por jogador no MVP.
- **Alternativa**: várias arenas isoladas no mesmo mapa e instância.
- **Impacto**: medições sem interferência de outros jogadores e lógica mais simples; custo por sessão
  maior (uma instância ocupa memória e CPU mesmo com um jogador). Reavaliar com os números da T1 (H5).

### D6 — Hospedagem do MVP

- **Recomendação**: um host em São Paulo, separado da API e do banco; VPS com vCPU dedicada no início
  (estimativa US$ 60–120/mês, 2–3 sessões simultâneas) e servidor dedicado acima disso (a partir de
  ~US$ 180/mês, 6–8 sessões). Contêiner ou `systemd`, decidido com dados da T1.
- **Alternativas**: Europa (mais barato, ~200 ms para o Brasil); nuvem grande em São Paulo (mais cara).
- **Impacto**: nada é contratado antes da T10 e dos bloqueios P1–P4, G1, D9 e D10. Valores sem
  cotação; pedir orçamento antes de decidir.

### D7 — Quando começar

Decidida: pesquisa e documentação podem seguir em paralelo à Academy; **nenhuma** PoC, instalação ou
desenvolvimento está autorizado agora. A PoC de medição (T1 + T2) é pré-requisito para T3 em diante.

### D8 — Visibilidade dos resultados

- **Recomendação**: resultados visíveis só ao próprio jogador; sem ranking nem perfil público no MVP;
  compartilhamento com equipe só por atribuição explícita (Fase 4/`Coaching`).
- **Alternativa**: rankings públicos (engajamento) — exigiria resultados mais resistentes a fraude do
  que a arquitetura garante (ver D3) e aumentaria a exposição de menores.
- **Impacto**: menos risco jurídico e de fraude; menos apelo competitivo.

### D9, D10, D11 — Questões jurídicas

Mantidas pendentes de validação jurídica: política de idade (ECA Digital, LGPD art. 14, classificação
indicativa do CS2), cobrança pelo acesso a servidores e uso de "CS" na marca, licença GPLv3 do
CounterStrikeSharp para o plugin próprio. Nenhuma delas bloqueia a documentação; todas bloqueiam a
produção, e a D11 bloqueia distribuir o plugin fora da empresa.

### D12 — Número da ADR

Decisão definitiva do responsável (2026-10-17): a ADR do Training é a **0021**
([0021-arquitetura-do-cs-tactical-training.md](../decisions/0021-arquitetura-do-cs-tactical-training.md)),
com status Proposta.

A **0020 fica reservada** para a futura arquitetura de busca da Academy com Elasticsearch
([proposta 2.3.1](../development/proposta-2.3.1-busca-elasticsearch.md)), que cita esse número. Essa
ADR ainda não foi criada nem aprovada; a reserva só evita que as duas iniciativas disputem o mesmo
número. Até ela existir, a sequência em `docs/decisions` pula de 0019 para 0021 de propósito.

### D13 — Mapas na Workshop públicos ou não listados

- **Recomendação**: preferir não listados, se a T1 confirmar que o servidor dedicado os carrega (H4);
  senão, públicos, com nome e descrição que deixem claro que dependem do servidor do CS Tactical.
- **Impacto**: públicos expõem os mapas (alguém pode treinar fora da plataforma, sem efeito nos
  resultados); não listados dependem de um comportamento não documentado.

### D14 — E-mail confirmado para vincular Steam

- **Recomendação**: exigir e-mail confirmado para vincular a conta Steam.
- **Alternativa**: seguir a P3 da Academy (estudar não exige e-mail confirmado).
- **Impacto**: impede contas descartáveis de ocupar SteamIDs (um SteamID por conta) e dá um canal
  para avisos de segurança; acrescenta um passo para o jogador.
