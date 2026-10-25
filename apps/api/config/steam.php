<?php

/*
| Vínculo da conta Steam por OpenID 2.0 (etapa M2, docs/training/mvp-m2-vinculo-steam.md).
|
| Só o perfil fixo da Steam, sem descoberta: o endpoint de login é conhecido e conferido em cada
| resposta (`openid.op_endpoint`) e na verificação (`check_authentication`).
*/

$official = 'https://steamcommunity.com/openid/login';

return [

    'official_endpoint' => $official,

    // Fora de produção pode ser trocado (STEAM_OPENID_ENDPOINT) pelo provedor falso da stack E2E
    // (decisão S1). Em produção é sempre o oficial, e o system:check-production-config confere.
    'openid_endpoint' => env('APP_ENV') === 'production'
        ? $official
        : env('STEAM_OPENID_ENDPOINT', $official),

    // Página do frontend que recebe a volta da Steam: FRONTEND_URL + este caminho + ?state=…
    'return_path' => '/painel/conta/steam',

    // Validade do `state` guardado na sessão ao iniciar o vínculo (segundos).
    'state_ttl' => 600,

    // Tolerância da data do `openid.response_nonce` em relação ao relógio do servidor (segundos).
    'nonce_skew' => 300,

    // Tempo máximo da verificação com a Steam (segundos).
    'timeout' => 5,

];
