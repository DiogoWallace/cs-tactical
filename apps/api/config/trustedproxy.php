<?php

/*
| Proxies confiáveis (ADR 0009, docs/architecture/autenticacao.md#ip-real-do-cliente).
|
| X-Forwarded-For e X-Forwarded-Proto são lidos apenas quando a conexão vem de um dos endereços
| abaixo; de qualquer outra origem eles são ignorados.
| - Desenvolvimento: só o IP fixo do container web (compose.yaml).
| - Produção: os IPs do proxy de borda, que substitui o X-Forwarded-For pelo IP da conexão
|   (docs/deploy/bloqueios-de-producao.md, P1 a P3). Nunca uma faixa ampla.
|
| Curingas ('*', '**') e 'REMOTE_ADDR' são descartados de propósito: confiar em qualquer
| remetente permitiria falsificar o IP do cliente e burlar o rate limiting.
*/

$proxies = array_values(array_filter(
    array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))),
    fn (string $proxy): bool => $proxy !== '' && ! in_array($proxy, ['*', '**', 'REMOTE_ADDR'], true),
));

return [

    // Lista de IPs ou faixas CIDR. Vazio = nenhum proxy confiável.
    'proxies' => $proxies === [] ? null : $proxies,

];
