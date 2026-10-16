# 0003 — Autenticação por sessão com Sanctum e proxy de mesma origem

- Status: Aceita (implementação na Fase 1; proxy validado na Fase 0)
- Data: 2026-10-16

## Contexto

O frontend é Next.js e a API é Laravel, em containers diferentes. Precisamos de autenticação segura (sem tokens no `localStorage`), compatível com Server Components, com CSRF e sem duas estratégias simultâneas.

## Decisão

- **Sanctum em modo SPA**: autenticação por sessão, com cookie HTTP-only e proteção CSRF via cookie `XSRF-TOKEN`.
- **Mesma origem**: o navegador só fala com o Next.js. O Next reescreve `/api/*` e `/sanctum/*` para o Laravel pela rede interna. Não há CORS.
- **Sessões no Redis.**
- **Fluxos de identidade** (cadastro, login, recuperação de senha, verificação de e-mail) com Laravel Fortify em modo headless.
- **Tokens pessoais do Sanctum desligados.** Se um cliente não-navegador surgir, ele será tratado numa nova ADR.

O fluxo completo, as regras para Server Components e o plano de validação estão em [`docs/architecture/autenticacao.md`](../architecture/autenticacao.md).

## Alternativas consideradas

- **BFF com token**: o Next guardaria um token do Sanctum num cookie criptografado e anexaria em cada chamada. Torna o Next responsável por criptografia, renovação e revogação de credenciais.
- **SPA cross-origin com CORS**: cookies entre portas e subdomínios, `SameSite` e CORS com credenciais; frágil em desenvolvimento e produção.

## Consequências

- Um único mecanismo, nativo do Laravel; logout e expiração são controlados pelo servidor.
- Server Components precisam repassar o cookie da requisição ao chamar a API, via um helper único (`serverFetch`).
- Em produção, a API deve ficar atrás do mesmo domínio do frontend (rewrite do Next ou proxy reverso).
