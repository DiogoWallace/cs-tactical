# Registro de decisões arquiteturais (ADRs)

Cada decisão relevante vira um arquivo `NNNN-titulo.md`, a partir de [`0000-template.md`](0000-template.md).
Uma ADR aceita não é editada para mudar de ideia: cria-se uma nova que a substitui e marca-se a antiga como "Substituída por NNNN".

| ADR | Título | Status |
|---|---|---|
| [0001](0001-monorepo-e-monolito-modular.md) | Monorepo com monólito modular e frontend desacoplado | Aceita |
| [0002](0002-laravel-13-e-php-84.md) | Laravel 13 com PHP 8.4 | Aceita |
| [0003](0003-autenticacao-sanctum-sessao-mesma-origem.md) | Autenticação por sessão com Sanctum e proxy de mesma origem | Aceita |
| [0004](0004-runtime-php-em-container.md) | Runtime PHP em container (serversideup/php, FPM + Nginx) | Aceita |
| [0005](0005-uuidv7-como-chave-primaria.md) | UUIDv7 como chave primária das entidades de domínio | Aceita |
| [0006](0006-ferramentas-de-qualidade-e-testes.md) | Ferramentas de qualidade e testes | Aceita |
| [0007](0007-papeis-de-equipe-e-funcoes-no-jogo.md) | Papéis de equipe separados das funções no jogo | Aceita |
| [0008](0008-conteudo-estruturado-da-academy.md) | Conteúdo da Academy em blocos estruturados | Aceita |
| [0009](0009-ip-real-do-cliente-e-proxies-confiaveis.md) | IP real do cliente e proxies confiáveis | Aceita |
| [0010](0010-fortify-headless-em-api-v1-auth.md) | Laravel Fortify headless sob `/api/v1/auth` | Aceita |
| [0011](0011-troca-de-email-com-confirmacao.md) | Troca de e-mail com confirmação no novo endereço | Aceita |
| [0012](0012-autorizacao-por-capacidades-e-policies.md) | Autorização: capacidades de plataforma e Policies por recurso | Aceita |
| [0013](0013-testes-e2e-em-stack-isolada.md) | Testes E2E com Playwright numa stack isolada e descartável | Aceita |
| [0014](0014-headers-de-seguranca-e-csp.md) | Headers de segurança e Content Security Policy | Aceita |
| [0015](0015-academy-e-learning-publicacao-e-visibilidade.md) | Módulos Academy e Learning, estados de publicação e visibilidade | Aceita |
| [0016](0016-schemas-de-blocos-e-importacao-de-conteudo.md) | Schemas versionados dos blocos e importação de conteúdo | Aceita |
| [0017](0017-progresso-diante-de-mudancas-no-conteudo.md) | Progresso do jogador diante de mudanças no conteúdo | Aceita |
| [0018](0018-quizzes-tentativas-e-historico.md) | Quizzes: correção no servidor, tentativas e histórico | Aceita |
| [0019](0019-midia-externa-markdown-seguro-e-arquivos.md) | Mídia externa, Markdown seguro e arquivos da Academy | Aceita |
| [0021](0021-arquitetura-do-cs-tactical-training.md) | Arquitetura do CS Tactical Training (prática em servidores CS2) | Proposta |
| [0022](0022-analise-de-partidas-por-demos.md) | Análise de partidas a partir de demos enviadas pelo jogador | Proposta |

A **0020** está reservada para a futura decisão de busca da Academy com Elasticsearch
([proposta 2.3.1](../development/proposta-2.3.1-busca-elasticsearch.md)); ainda não foi criada nem
aprovada.
