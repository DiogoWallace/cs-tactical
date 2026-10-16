# 0011 — Troca de e-mail com confirmação no novo endereço

- Status: Aceita (implementada na etapa 1.5)
- Data: 2026-10-16

## Contexto

O e-mail é o identificador de login e o destino da recuperação de senha. Trocá-lo direto, só com a senha atual, tem dois riscos: um erro de digitação deixa o usuário sem acesso à recuperação, e quem estiver com uma sessão aberta (e a senha) leva a conta para um endereço que controla.

## Decisão

1. **O novo endereço fica pendente** (`users.pending_email`) até ser confirmado. O e-mail atual continua valendo para login e recuperação enquanto isso.
2. **O pedido exige a senha atual** (`POST /api/v1/me/email`, limite de 5/min por usuário) e recusa endereços em uso por outra conta (sem diferenciar maiúsculas) e o próprio endereço atual.
3. **O link de confirmação vai para o novo endereço**. Mesmo desenho da verificação de e-mail ([ADR 0010](0010-fortify-headless-em-api-v1-auth.md)): URL assinada **relativa** da API (60 minutos), aberta pela página `/confirmar-email/{id}/{hash}` do frontend, que chama `GET /api/v1/me/email/confirm/{id}/{hash}` com a mesma query. Exige sessão da própria conta.
4. **O `hash` do link é `sha256(pending_email)`**: um pedido novo (ou o cancelamento, `DELETE /api/v1/me/email`) invalida os links anteriores.
5. **Na confirmação**, numa transação: o e-mail passa a ser o pendente, já verificado (o clique prova a posse); os tokens de redefinição de senha do endereço antigo e do novo são apagados. Se outra conta tiver ficado com o endereço nesse meio-tempo, responde 422 e nada muda.
6. **O endereço antigo recebe um aviso** de que o e-mail foi alterado, com o novo mascarado (`jo*****@exemplo.com`).
7. As sessões abertas continuam: a troca de e-mail não muda a senha.

## Alternativas consideradas

- **Trocar na hora e marcar como não verificado**: um erro de digitação ou uma sessão sequestrada leva a conta embora sem passar pelo dono do novo endereço.
- **Confirmar também pelo endereço antigo**: mais seguro contra sessão sequestrada, mas inviabiliza a troca quando o usuário perdeu o acesso ao e-mail antigo, que é um dos motivos comuns para trocar. A senha atual cobre esse caso.
- **Coluna única para `pending_email`**: bloquearia um endereço só por alguém tê-lo pedido; a unicidade fica no `email`, conferida na confirmação.

## Consequências

- Até a confirmação, login e recuperação de senha continuam pelo endereço antigo.
- `UserResource` expõe `pending_email` ao próprio usuário, para o frontend mostrar o pedido pendente.
- Testes em `tests/Feature/Identity/EmailChangeTest.php` (expiração, adulteração, substituição, cancelamento, outra conta, sessão, endereço tomado, aviso ao endereço antigo).
