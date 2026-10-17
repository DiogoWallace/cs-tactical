# 0021 — Arquitetura do CS Tactical Training (prática em servidores CS2)

- Status: Proposta (arquitetura aprovada conceitualmente em 2026-10-17; aceite depende da PoC de medição)
- Data: 2026-10-17

## Contexto

O CS Tactical quer que o jogador pratique dentro do CS2, em servidores próprios, e acompanhe na
plataforma indicadores vindos da execução real, não declarados por ele. A pesquisa da etapa T0
([viabilidade-cs2.md](../training/viabilidade-cs2.md)) mostrou que:

- o servidor dedicado CS2 é oficial e hospedável (SteamCMD, Linux, GSLT);
- o código no servidor pode ser um plugin C# (Metamod + CounterStrikeSharp, comunitário, que quebra
  com frequência após atualizações do jogo) ou `cs_script` (oficial, embutido no mapa, **sem rede e
  sem SteamID**);
- o servidor valida o jogador com a Steam, e o plugin lê o SteamID validado (`AuthorizedSteamID`);
  o site pode vincular a conta à Steam por OpenID 2.0;
- o estado do jogo é observável por tick (64/s); abaixo disso, e para "tempo de reação", só há
  estimativas.

Restrições: o servidor de jogo não pode acessar o banco; a produção segue bloqueada por P1–P4 e G1;
a Academy (Fase 2) é a prioridade e não pode ser afetada; hoje só pesquisa e documentação estão
autorizadas.

## Decisão

1. **Módulo próprio** no monólito (`Training`, nome aprovado conceitualmente; `Coaching` proposto para
   a Fase 4), independente de `Academy` e `Learning`. O vínculo Steam fica no `Identity`. Integração
   com a Academy por chaves de habilidade e composição no frontend; um módulo de recomendações, se
   existir, só lê os outros (sem ciclos).
2. **Plugin CounterStrikeSharp como único agente no servidor**: identifica o jogador (SteamID validado
   pela Steam), executa o Training Engine a partir de definições versionadas e envia fatos à API. O
   mapa fornece cenário e entidades por um contrato de nomes; `cs_script` é opcional e nunca fonte
   única de métrica.
3. **O servidor de jogo é um cliente não confiável da API**: credencial própria por servidor,
   HMAC-SHA256 por requisição sobre TLS, timestamp e nonce contra replay, escopo restrito às rotas
   `/api/v1/training/agent/*`, entrada por host próprio na borda. Nenhum acesso a banco ou Redis.
   A assinatura **autentica a origem declarada** e a integridade da requisição; **não impede
   telemetria falsificada por um servidor comprometido**, que tem o segredo. Por isso autenticação e
   confiabilidade do resultado são avaliadas separadamente (item 7).
4. **Identidade em duas fontes da Steam**: SteamID do site só por OpenID verificado no backend;
   presença no servidor só por `AuthorizedSteamID`. Uma sessão pertence a um usuário e a um SteamID,
   alocada a um servidor; o claim só é aceito do servidor alocado e do SteamID da reserva. Servidores
   sem autenticação Steam são `lab` e não geram histórico oficial.
5. **Telemetria em lotes por HTTPS** durante a sessão + resumo no fim; idempotência por `batch_id` e
   `(session_id, seq)`; buffer em disco no plugin. **Sem WebSocket** enquanto não houver requisito de
   tempo real; comandos da API voltam na resposta do heartbeat.
6. **A API calcula o resultado oficial** com calculadoras e definições de métrica versionadas; o
   plugin só dá retorno imediato no jogo. Cada métrica é classificada como **observada diretamente**,
   **calculada** ou **estimativa**, e o tipo aparece na interface.
7. **Confiabilidade dos resultados**: todo resultado termina em um estado — **validado**,
   **incompleto**, **suspeito** ou **inválido** (`validated`, `incomplete`, `suspect`, `invalid`) —
   com os motivos. Só `validated` entra em tendências, recordes e recomendações; `suspect` só muda por
   revisão registrada.
8. **Versões registradas**: cada sessão grava build do CS2, tickrate, plugin, Training Engine, mapa
   (versão e hash) e versão do exercício; cada resultado grava a versão das regras de cálculo; cada
   valor, a versão da definição da métrica.
9. **Sem comparação automática entre versões incompatíveis**: valores só são comparados
   automaticamente dentro da mesma chave de comparabilidade (exercício, grupo, métrica@versão,
   calculadora compatível, época do jogo). O padrão de uma versão nova é **não** comparável.
10. **Dados**: eventos brutos limitados ao contrato (janelas de evidência, sem fluxo por tick, sem IP),
    particionados e mantidos por 90 dias; tentativas, resultados e métricas enquanto a conta existir;
    dados do jogador apagados em cascata com a conta.
11. **Infraestrutura isolada**: servidores de jogo em hosts separados da API e do banco, uma instância
    por jogador no MVP, Training tratado como recurso degradável.
12. **Medição antes da implementação**: a PoC de medição (laboratório + comparação com demo) é
    pré-requisito para implementar qualquer parte do Training na aplicação; ela depende de autorização
    própria.

## Alternativas consideradas

- **Plugin gravando no banco**: credencial de banco em máquina exposta; vetado.
- **Logs HTTP nativos do servidor ou só demos**: sem identidade de sessão, autenticação forte ou
  versionamento; demos ficam como ferramenta de calibração e auditoria.
- **Toda a lógica em `cs_script`**: sem rede e sem SteamID na API documentada.
- **WebSocket**: sem necessidade de tempo real; mais estado e reconexão para gerir.
- **Token Bearer ou mTLS**: Bearer não protege o corpo nem impede replay; mTLS fica para a expansão.
- **Tratar "assinado" como "confiável"**: falha exatamente no cenário de servidor comprometido.
- **Comparar tudo por padrão e marcar exceções**: uma mudança esquecida misturaria medidas diferentes
  na mesma linha do tempo.
- **Guardar o fluxo completo por tick**: ~30 vezes mais volume sem métrica prevista que o exija.

## Consequências

- Cada patch do CS2 exige o runbook de atualização; o Training terá janelas de indisponibilidade
  fora do nosso controle.
- O `RouteAuthorizationTest` ganha a categoria "rotas de máquina"; a borda de produção ganha um host
  de ingestão (P1–P3 valem para ele).
- Resultados do Training não servem, sozinhos, para fins com valor externo (prêmios, seleção,
  certificados): um servidor comprometido pode forjar dados plausíveis.
- Métricas de tempo (reflexo, tempo até eliminar) são apresentadas como estimativas e comparadas só
  com o próprio histórico.
- Mudanças de versão abrem novas séries no histórico do jogador em vez de continuar a anterior.
- O nome `Coaching` para a Fase 4 é proposta; os documentos daquela fase só mudam com aprovação.
- A ADR passa a "Aceita" depois da PoC de medição e das decisões pendentes
  ([roadmap](../training/roadmap.md#decisões)); D9–D11 aguardam validação jurídica.
- Documentação: [docs/training/](../training/visao-geral.md).
