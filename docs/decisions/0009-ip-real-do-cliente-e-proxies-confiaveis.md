# 0009 — IP real do cliente e proxies confiáveis

- Status: Aceita
- Data: 2026-10-16

## Contexto

O navegador só fala com o Next.js, que encaminha `/api/*` e `/sanctum/*` para o Laravel ([ADR 0003](0003-autenticacao-sanctum-sessao-mesma-origem.md)). Para o Laravel, toda requisição parece vir do container `web`. Sem tratamento, o rate limiting por IP (login, cadastro, recuperação de senha) colocaria todos os usuários no mesmo contador.

Confiar em `X-Forwarded-For` de qualquer remetente resolve isso, mas abre outra falha: quem falsifica o cabeçalho escolhe o próprio IP e escapa do rate limiting.

Comportamento do Next.js 16.4, verificado no código (`base-server.js`, `proxy-request.js`) e com requisições reais na etapa 1.0:

| Caminho | `X-Forwarded-For` que chega ao destino |
|---|---|
| Rewrite `/api/*` → Laravel | O valor enviado pelo cliente, **sem alteração**. Se o cliente não envia, nenhum. |
| Páginas e `proxy.ts` | `??=` com o IP da conexão: mantém o valor do cliente quando existe, senão usa o IP do socket. |

O Next.js nunca acrescenta o IP da conexão a um `X-Forwarded-For` existente. Portanto **ele não pode ser a fonte do IP real**: só repassa o que recebeu. Esse comportamento não é documentado como contrato e pode mudar entre versões.

## Decisão

### Regras do Laravel (valem em qualquer ambiente)

1. **Só endereços listados em `TRUSTED_PROXIES` são confiáveis.** A lista vazia (padrão) significa nenhum proxy confiável. `config/trustedproxy.php` recusa curingas (`*`, `**`, `REMOTE_ADDR`).
2. **Só `X-Forwarded-For` e `X-Forwarded-Proto` são lidos**, e apenas quando a conexão vem de um proxy confiável. `Forwarded` (RFC 7239), `X-Forwarded-Host`, `-Port`, `-Prefix`, `X-Real-IP`, `Client-IP`, `True-Client-IP` e `CF-Connecting-IP` são sempre ignorados. URLs assinadas e links de e-mail não dependem do host da requisição ([ADR 0010](0010-fortify-headless-em-api-v1-auth.md)).
3. **O IP do cliente é a entrada mais à direita de `X-Forwarded-For` que não seja um proxy confiável** (comportamento do Symfony). Entradas que o cliente prefixa são ignoradas.

### Desenvolvimento (Docker Compose)

4. O container `web` tem IP fixo na rede interna (`WEB_INTERNAL_IP`, padrão `172.31.250.10`) e é o único valor de `TRUSTED_PROXIES`. O gateway do Docker (por onde chega a porta 8000 publicada no host) e os demais containers não são confiáveis.
5. `serverFetch` (Server Components → API) repassa o `X-Forwarded-For` que o Next.js recebeu, para que as duas formas de chamar a API identifiquem o cliente da mesma maneira.

### Produção: a borda é a única fonte do IP real

6. **Um proxy de borda é obrigatório** (ele já é necessário para TLS) e é o **único ponto de entrada público**. Nem o Next.js nem a API podem ser acessíveis da internet sem passar por ele.
7. **A borda é responsável por estabelecer o IP real e por descartar o que o cliente enviou:**
   - **define** `X-Forwarded-For` com o IP da conexão TCP, **substituindo** qualquer valor recebido (Nginx: `proxy_set_header X-Forwarded-For $remote_addr;`, e não `$proxy_add_x_forwarded_for`);
   - **define** `X-Forwarded-Proto` com o esquema real (`https`);
   - **remove** `Forwarded`, `X-Forwarded-Host`, `X-Forwarded-Port`, `X-Forwarded-Prefix`, `X-Real-IP`, `Client-IP`, `True-Client-IP` e `CF-Connecting-IP` vindos do cliente;
   - se houver CDN na frente da borda, a borda restaura o IP do cliente **apenas** quando a conexão vem das faixas publicadas pela CDN (Nginx `real_ip`/`set_real_ip_from`), e só então aplica as regras acima.
8. **Topologia recomendada: a borda encaminha `/api/*` e `/sanctum/*` direto para a API**, mantendo a mesma origem (ADR 0003 já admite "rewrite do Next ou proxy reverso"). `TRUSTED_PROXIES` contém os IPs da borda. Assim o IP usado no rate limiting não depende de nenhum comportamento do Next.js. As rotas com limite por IP (login, cadastro, recuperação de senha) são todas chamadas pelo navegador, portanto passam por esse caminho.
9. **Se o Next.js também chamar a API** (`serverFetch`), os IPs dele entram em `TRUSTED_PROXIES` junto com os da borda. Como a borda substitui o cabeçalho, o Next só pode repassar um valor que a própria borda escreveu. Essas chamadas são de leitura autenticada, com limite por ID do usuário.
10. **Encaminhar `/api/*` pelo rewrite do Next em produção** é aceitável apenas se os itens 6 e 7 forem atendidos. A segurança continua garantida pela borda, mas a precisão do IP passa a depender do Next.js repassar o cabeçalho sem alteração (ver "Modos de falha").

### Modos de falha

| Situação | Efeito no IP usado pelo Laravel | Falsificação possível? |
|---|---|---|
| Borda conforme o item 7 | IP real do cliente | Não |
| Uma versão do Next passa a **acrescentar** o IP da borda (topologia do item 10) | IP da borda, igual para todos | Não. Todos compartilham o mesmo contador (risco de disponibilidade); adicionar a borda a `TRUSTED_PROXIES` corrige |
| Uma versão do Next passa a **remover** o cabeçalho (item 10) | IP do Next, igual para todos | Não (mesmo efeito) |
| `TRUSTED_PROXIES` vazio | IP do último salto, igual para todos | Não |
| Borda acrescenta em vez de substituir (`$proxy_add_x_forwarded_for`) | IP real (entrada mais à direita) | Não, enquanto ninguém entre a borda e a API acrescentar entradas. Proibido pelo item 7 para não depender disso |
| **Next.js ou API acessível sem passar pela borda** | **O valor que o cliente quiser** | **Sim. É bloqueio de produção** |
| **`TRUSTED_PROXIES` com faixa que inclua endereços controláveis por terceiros** | **O valor que o cliente quiser** | **Sim. É bloqueio de produção** |

Toda falha de configuração do lado do Laravel é *fail-closed*: na pior hipótese, os clientes compartilham um contador, mas nenhum deles escolhe o próprio IP. A falsificação só é possível se a borda for contornada ou se a lista de confiança for ampla demais. As duas condições estão em [bloqueios de produção](../deploy/bloqueios-de-producao.md).

## Alternativas consideradas

- **Confiar na sub-rede inteira do Docker**: inclui o gateway, então qualquer processo do host falsificaria o IP chamando a porta 8000.
- **Confiar em `*`**: qualquer cliente falsifica o IP.
- **Normalizar o cabeçalho num `proxy.ts`**: o `proxy.ts` não tem acesso confiável ao IP da conexão quando o cliente envia `X-Forwarded-For` (ver tabela do contexto), então não acrescenta segurança.
- **Resolver o IP do container `web` por DNS a cada requisição**: custo por requisição e dependência do DNS interno; o IP fixo é mais simples e verificável.
- **Depender do Next.js para identificar o cliente em produção**: o comportamento observado não é contrato documentado; a borda é o único componente que vê a conexão TCP do cliente.

## Consequências

- Rate limiting por IP funciona por cliente, e trocar o cabeçalho falsificado não cria um contador novo (testes em `tests/Feature/Http/TrustedProxiesTest.php`, incluindo as topologias de produção e os modos de falha).
- **Em desenvolvimento**, sem borda, o IP do cliente é o que ele declarar ao Next.js (ou o IP do container `web`, se não declarar nada). Isso é aceitável apenas localmente.
- A rede `cs-tactical-internal` tem faixa fixa (`INTERNAL_SUBNET`). Se ela conflitar com outra rede da máquina, as três variáveis do `.env` da raiz precisam ser alteradas juntas.
- O deploy público depende da borda configurada e verificada conforme os [bloqueios de produção](../deploy/bloqueios-de-producao.md).
