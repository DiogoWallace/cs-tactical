# CS Tactical Training — visão geral

> **Status: planejamento (etapa T0, 2026-10-17).** Nada implementado. As decisões estão na
> [ADR 0021](../decisions/0021-arquitetura-do-cs-tactical-training.md) (**Proposta**) e a situação de
> cada decisão em [roadmap.md](roadmap.md#decisões). Arquitetura aprovada conceitualmente em
> 2026-10-17; **só pesquisa e documentação estão autorizadas** (nenhuma PoC, instalação ou
> desenvolvimento). Não interfere na Fase 2 (Academy), que é a prioridade.

## Objetivo

O jogador **aprende** na Academy, **pratica** dentro do CS2 em servidores do CS Tactical e
**acompanha** a própria evolução na plataforma. Os resultados vêm da execução real no servidor,
nunca de valores informados pelo jogador.

## Fluxo do jogador

1. Entra no CS Tactical (conta da Fase 1) e vincula a conta Steam uma vez (OpenID).
2. Escolhe um exercício (ex.: counter-strafe).
3. Reserva uma sessão; a plataforma aloca um servidor e mostra o botão "Conectar".
4. Conecta no CS2. O servidor confirma a identidade com a Steam e a reserva com a API.
5. Executa o exercício; o plugin observa o jogo e envia os fatos à API.
6. Termina (ou o tempo acaba); a API calcula os indicadores.
7. Consulta o resultado, o histórico e as recomendações (aulas e exercícios).

## Documentos

| Documento | Conteúdo |
|---|---|
| [viabilidade-cs2.md](viabilidade-cs2.md) | O que o servidor CS2, o CounterStrikeSharp, o `cs_script` e a Steam permitem de fato, com fontes e classificação |
| [arquitetura.md](arquitetura.md) | Componentes, fluxos, identidade e sessões, falhas, integração com a Academy |
| [telemetria.md](telemetria.md) | Contrato plugin ↔ API: transporte, autenticação, eventos, idempotência, falhas |
| [fontes-de-telemetria.md](fontes-de-telemetria.md) | Estudo T0.1: plugin no servidor × Game State Integration × demos; o que cada fonte mede, confiança, custo, experimentos E1–E3 |
| [metricas.md](metricas.md) | Catálogo de métricas: origem, cálculo, limites, validade, evolução e recomendações |
| [modelagem.md](modelagem.md) | Proposta de modelo relacional, retenção e volume |
| [mapas-e-exercicios.md](mapas-e-exercicios.md) | Training Engine, definição versionada de exercícios, contrato de entidades do mapa |
| [infraestrutura.md](infraestrutura.md) | Laboratório, MVP e expansão: recursos, operação e custos estimados |
| [seguranca.md](seguranca.md) | Análise de ameaças, controles e privacidade (inclusive menores) |
| [roadmap.md](roadmap.md) | Etapas T0–T10, prova de conceito, critérios de aceite e decisões pendentes |

## Conclusão da viabilidade (resumo)

- **Viável**, com uma arquitetura em que o **servidor de jogo é um cliente não confiável da API**,
  autenticado por credencial própria, e a identidade do jogador vem de duas fontes independentes da
  Steam (OpenID no site e validação do ticket no servidor).
- Métricas por **tick** (64 por segundo, ~15,6 ms) são observáveis: velocidade, botões, tiros, dano,
  gatilhos do mapa. Abaixo disso (subtick), e para "tempo de reação humano", só há **estimativas**.
- O risco operacional dominante é externo: cada atualização do CS2 pode derrubar o
  plugin por horas ou dias. O produto precisa tratar o Training como **recurso que pode ficar
  indisponível**, sem afetar Academy e conta.
- Produção continua bloqueada por P1–P4 e G1 ([bloqueios de produção](../deploy/bloqueios-de-producao.md)),
  e o servidor de jogo acrescenta requisitos próprios ([seguranca.md](seguranca.md)).

## Posicionamento

Há concorrente com o mesmo conceito: a Refrag (servidores privados de treino por assinatura, com
estatísticas por sessão desde maio de 2026). O diferencial possível do CS Tactical é a ligação
**aula → prática → evolução → recomendação**, em português, e, na Fase 4, a atribuição por
treinadores de equipe. A decisão de produto sobre esse posicionamento é anterior ao investimento em
infraestrutura (T1 é barata; T10 não é).

## Glossário

| Termo | Significado |
|---|---|
| Servidor (de treino) | Uma instância do servidor dedicado CS2 registrada na plataforma |
| Plugin | O plugin C# (CounterStrikeSharp) do CS Tactical que roda no servidor |
| Training Engine | Núcleo do plugin que executa exercícios a partir de definições versionadas |
| Exercício | Atividade com regras, tentativas e métricas (ex.: counter-strafe) |
| Versão do exercício | Definição imutável (mapa, regras, métricas); resultados citam a versão |
| Sessão | Uma reserva e execução de um exercício por um jogador num servidor |
| Tentativa | Uma repetição do exercício dentro da sessão |
| Evento | Fato observado pelo plugin (tiro, velocidade, gatilho), enviado à API |
| Métrica | Valor calculado pela API a partir dos eventos, com definição versionada |
| Tick | Passo da simulação do servidor (64 por segundo no CS2) |

## Nome do módulo

O plano atual já reserva `Training` para a Fase 4 ("sessões e atribuições de treino", tabelas
`training_sessions` e `training_assignments` em [modelo-conceitual.md](../database/modelo-conceitual.md)).
Decisão **D1** ([roadmap.md](roadmap.md#d1--nome-do-módulo)), aprovada conceitualmente em
2026-10-17: `Training` fica reservado para os treinamentos dentro do CS2, e `Coaching` é o nome
**proposto** para agenda e atribuições de equipe (Fase 4). Os documentos de outras fases
([visao-geral.md](../architecture/visao-geral.md), [modelo-conceitual.md](../database/modelo-conceitual.md))
**não** foram alterados; a mudança será feita quando a proposta for aprovada formalmente.
