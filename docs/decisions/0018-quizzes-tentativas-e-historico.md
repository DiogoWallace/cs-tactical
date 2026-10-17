# 0018 — Quizzes: correção no servidor, tentativas e histórico

- Status: Aceita (etapa 2.0)
- Data: 2026-10-17

## Contexto

Decisões de produto: 3 tentativas e nota mínima de 70% por padrão, ajustáveis por quiz; reprovar
não impede continuar estudando. Perguntas e respostas podem ser corrigidas depois de jogadores já
terem respondido. O gabarito só pode ser revelado quando o jogador não puder mais usá-lo para
melhorar a nota.

## Decisão

### Tentativas

1. **Configuração por quiz**: `max_attempts` (padrão 3, de 1 a 20) e `pass_percent` (padrão 70,
   de 1 a 100).
2. **Correção só no servidor.** A tentativa é iniciada por uma chamada própria, que devolve as
   perguntas **sem gabarito e sem explicações**.
3. **Uma tentativa aberta por vez** por jogador e quiz (índice único parcial). Iniciar com uma
   aberta devolve a aberta, sem consumir tentativa.
4. **Limite**: tentativas abertas e enviadas contam para `max_attempts`.
5. **Depois de aprovado não há tentativa nova** daquele quiz (409 `quiz_already_passed`).
   Decisão derivada da P7, confirmada: com o gabarito liberado pela aprovação, uma tentativa nova
   não mediria nada. **A aprovação é preservada mesmo que a revisão do quiz mude depois**, e o
   limite de tentativas não é reiniciado; só um **outro quiz**, com `key` própria, permite novas
   tentativas. A divulgação do gabarito continua regida pelos itens 11–14.
6. **Reprovação não bloqueia** aulas, conclusão de aulas nem do curso.

### Snapshot e histórico

7. Ao iniciar, a tentativa grava um *snapshot* das perguntas e opções daquele momento — textos,
   ordem, gabarito e explicações. A correção e a revisão usam o snapshot; editar o quiz no meio de
   uma tentativa não muda nada nela.
8. Cada resposta guarda as opções escolhidas e se acertou. Perguntas e opções de um quiz que já
   teve **qualquer** tentativa (aberta ou enviada) não são apagadas: são **aposentadas**
   (`retired_at`), porque uma tentativa aberta ainda vai responder o que está no seu snapshot.
9. **Revisão do quiz** (decisão P2): `quizzes.revision` sobe quando perguntas, opções ou gabarito
   mudam; a tentativa guarda a revisão usada. **Mudar a revisão não reinicia o limite de
   tentativas.** Uma mudança substancial na avaliação cria **outro quiz**, com outra `key`, e
   começa do zero; as tentativas do quiz anterior continuam no histórico com seus snapshots.
10. **Pontuação**: escolha única ou múltipla; cada pergunta vale 1 ponto e só pontua se a seleção
    for exatamente o gabarito (P6). Nota em percentual com duas casas.

### O que o jogador vê (decisão P7, aplicada pelo backend)

11. **Revisão liberada** = o jogador foi aprovado em alguma tentativa desse quiz **ou** esgotou as
    tentativas. É calculada no servidor a cada resposta, nunca informada pelo cliente.
12. **Antes da liberação**, em qualquer tentativa enviada: nota, aprovado/reprovado, tentativas
    restantes e **acerto/erro por pergunta**. Nunca: quais opções são corretas nem explicações.
13. **Depois da liberação**: para cada tentativa enviada, o gabarito e as explicações **do snapshot
    daquela tentativa** (o que o jogador viu), além das opções escolhidas.
14. Todas as respostas da API que descrevem tentativas passam por um único componente que aplica
    11–13; a regra tem testes de API em matriz (antes/depois de aprovar, antes/depois de esgotar,
    tentativa aberta, tentativa de outro jogador).

### Conteúdo arquivado

15. Uma tentativa aberta numa aula que for arquivada não pode mais ser enviada (404, decisão P1);
    o histórico das tentativas enviadas continua visível com as mesmas regras de liberação.

## Alternativas consideradas

- **Correção no navegador**: exporia o gabarito.
- **Explicações sempre visíveis**: explicações costumam citar a resposta certa.
- **Reiniciar tentativas a cada revisão**: permitiria contornar o limite com correções pequenas.
- **Editar perguntas no lugar, sem snapshot**: tentativas antigas mostrariam texto que o jogador
  nunca viu.

## Consequências

- O acerto/erro por pergunta, somado às tentativas, permite eliminar opções em perguntas de
  escolha única com poucas opções. É um compromisso aceito em favor do aprendizado; o
  `max_attempts` limita esse efeito, e o autor pode usar mais opções.
- O snapshot ocupa espaço por tentativa (pequeno).
- Limites de requisição e de tentativas dificultam força bruta.
