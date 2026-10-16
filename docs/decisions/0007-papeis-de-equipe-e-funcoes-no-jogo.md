# 0007 — Papéis de equipe separados das funções no jogo

- Status: Aceita (implementação na Fase 3)
- Data: 2026-10-16

## Contexto

Uma equipe tem membros com permissões diferentes (quem convida, quem edita estratégias) e jogadores com funções táticas (IGL, AWPer, Entry, Support). Misturar os dois conceitos leva a regras como "o IGL pode remover membros", que acoplam tática a segurança.

## Decisão

Três dimensões independentes:

| Dimensão | Onde fica | Afeta autorização? | Valores iniciais |
|---|---|---|---|
| Papel na plataforma | `users.platform_role` | Sim (administração e conteúdo da Academy) | `user`, `editor`, `admin` |
| Papel na equipe | `team_members.role` | Sim (permissões dentro da equipe) | `owner`, `manager`, `coach`, `analyst`, `player` |
| Função no jogo | `team_members.game_role` | **Não** | `igl`, `awper`, `entry`, `support`, `lurker` (opcional) |

- As permissões de cada papel de equipe ficam num enum PHP (`TeamRole`) e são consultadas pelas Policies.
- A função no jogo é informação tática: aparece em estratégias e escalações, mas nunca é verificada em Policies.
- Ser admin da plataforma não dá acesso a dados privados de equipes.

## Consequências

- Um jogador pode ser IGL sem ser manager, e um coach pode gerenciar a equipe sem ter função no jogo.
- Testes de Policy cobrem cada papel de equipe; nenhum teste depende de `game_role`.
