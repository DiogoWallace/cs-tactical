<?php

/*
| Mensagens do broker de redefinição de senha.
| `sent` e `token` são genéricas de propósito: não revelam se o e-mail tem conta
| (ver Http/Responses do módulo Identity).
*/

return [

    'reset' => 'Sua senha foi redefinida. Entre com a nova senha.',
    'sent' => 'Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.',
    'throttled' => 'Aguarde antes de tentar novamente.',
    'token' => 'Este link de redefinição é inválido ou expirou. Solicite um novo.',
    'user' => 'Este link de redefinição é inválido ou expirou. Solicite um novo.',

];
