<?php

/*
| Mensagens de validação em português (APP_LOCALE=pt_BR).
|
| Só as regras usadas pela API estão aqui; as demais caem no inglês do framework
| (APP_FALLBACK_LOCALE=en). Ao usar uma regra nova em um formulário, traduza-a aqui.
| As mensagens chegam ao frontend em `errors` (docs/api/convencoes.md#erros).
*/

return [

    'confirmed' => 'A confirmação de :attribute não confere.',
    'current_password' => 'A senha atual está incorreta.',
    'different' => 'O campo :attribute deve ser diferente de :other.',
    'email' => 'Informe um e-mail válido.',
    'in' => 'O valor de :attribute é inválido.',
    'max' => [
        'string' => 'O campo :attribute deve ter no máximo :max caracteres.',
    ],
    'min' => [
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'password' => [
        'letters' => 'A senha deve conter pelo menos uma letra.',
        'mixed' => 'A senha deve conter letras maiúsculas e minúsculas.',
        'numbers' => 'A senha deve conter pelo menos um número.',
        'symbols' => 'A senha deve conter pelo menos um símbolo.',
        'uncompromised' => 'Esta senha apareceu em um vazamento de dados. Escolha outra.',
    ],
    'regex' => 'O formato de :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'timezone' => 'Informe um fuso horário válido.',
    'unique' => 'Este :attribute já está em uso.',

    /*
    | Mensagens específicas por campo e regra.
    */
    'custom' => [
        'nickname' => [
            'regex' => 'O apelido deve ter de 3 a 24 caracteres, usando letras sem acento, números, ponto, hífen ou sublinhado.',
        ],
        'country_code' => [
            'in' => 'Selecione um país da lista.',
        ],
        'password' => [
            'different' => 'A nova senha deve ser diferente da atual.',
        ],
    ],

    'attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'password' => 'senha',
        'password_confirmation' => 'confirmação da senha',
        'nickname' => 'apelido',
        'country_code' => 'país',
        'timezone' => 'fuso horário',
        'current_password' => 'senha atual',
        'bio' => 'bio',
    ],

];
