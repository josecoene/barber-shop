<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mensagens de validação
    |--------------------------------------------------------------------------
    |
    | Tradução feita à mão a partir do arquivo en do Laravel 13
    | (vendor/laravel/framework/src/Illuminate/Translation/lang/en).
    | Usam "O campo :attribute" para servir a qualquer campo, e o nome
    | de cada campo em português fica no bloco "attributes" no fim.
    |
    */

    'accepted' => 'O campo :attribute deve ser aceito.',
    'accepted_if' => 'O campo :attribute deve ser aceito quando :other for :value.',
    'active_url' => 'O campo :attribute deve ser uma URL válida.',
    'after' => 'O campo :attribute deve ser uma data posterior a :date.',
    'after_or_equal' => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
    'alpha' => 'O campo :attribute deve conter apenas letras.',
    'alpha_dash' => 'O campo :attribute deve conter apenas letras, números, hifens e sublinhados.',
    'alpha_num' => 'O campo :attribute deve conter apenas letras e números.',
    'any_of' => 'O campo :attribute é inválido.',
    'array' => 'O campo :attribute deve ser uma lista.',
    'array_keys' => 'O campo :attribute deve conter apenas estas chaves: :values.',
    'ascii' => 'O campo :attribute deve conter apenas letras, números e símbolos simples (sem acento).',
    'base64' => 'O campo :attribute deve ser um texto Base64 válido.',
    'before' => 'O campo :attribute deve ser uma data anterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data igual ou anterior a :date.',
    'between' => [
        'array' => 'O campo :attribute deve ter entre :min e :max itens.',
        'file' => 'O campo :attribute deve ter entre :min e :max kilobytes.',
        'numeric' => 'O campo :attribute deve estar entre :min e :max.',
        'string' => 'O campo :attribute deve ter entre :min e :max caracteres.',
    ],
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'can' => 'O campo :attribute contém um valor não autorizado.',
    'confirmed' => 'A confirmação do campo :attribute não confere.',
    'contains' => 'Falta um valor obrigatório no campo :attribute.',
    'current_password' => 'A senha está incorreta.',
    'date' => 'O campo :attribute deve ser uma data válida.',
    'date_equals' => 'O campo :attribute deve ser uma data igual a :date.',
    'date_format' => 'O campo :attribute deve seguir o formato :format.',
    'decimal' => 'O campo :attribute deve ter :decimal casas decimais.',
    'declined' => 'O campo :attribute deve ser recusado.',
    'declined_if' => 'O campo :attribute deve ser recusado quando :other for :value.',
    'different' => 'Os campos :attribute e :other devem ser diferentes.',
    'digits' => 'O campo :attribute deve ter :digits dígitos.',
    'digits_between' => 'O campo :attribute deve ter entre :min e :max dígitos.',
    'dimensions' => 'O campo :attribute tem dimensões de imagem inválidas.',
    'distinct' => 'O campo :attribute tem um valor repetido.',
    'doesnt_contain' => 'O campo :attribute não pode conter nenhum destes valores: :values.',
    'doesnt_end_with' => 'O campo :attribute não pode terminar com nenhum destes valores: :values.',
    'doesnt_start_with' => 'O campo :attribute não pode começar com nenhum destes valores: :values.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'encoding' => 'O campo :attribute deve usar a codificação :encoding.',
    'ends_with' => 'O campo :attribute deve terminar com um destes valores: :values.',
    'enum' => 'O valor escolhido no campo :attribute é inválido.',
    'exists' => 'O valor escolhido no campo :attribute é inválido.',
    'extensions' => 'O campo :attribute deve ter uma destas extensões: :values.',
    'file' => 'O campo :attribute deve ser um arquivo.',
    'filled' => 'O campo :attribute deve ter um valor.',
    'gt' => [
        'array' => 'O campo :attribute deve ter mais de :value itens.',
        'file' => 'O campo :attribute deve ter mais de :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser maior que :value.',
        'string' => 'O campo :attribute deve ter mais de :value caracteres.',
    ],
    'gte' => [
        'array' => 'O campo :attribute deve ter :value itens ou mais.',
        'file' => 'O campo :attribute deve ter :value kilobytes ou mais.',
        'numeric' => 'O campo :attribute deve ser maior ou igual a :value.',
        'string' => 'O campo :attribute deve ter :value caracteres ou mais.',
    ],
    'hex_color' => 'O campo :attribute deve ser uma cor hexadecimal válida.',
    'image' => 'O campo :attribute deve ser uma imagem.',
    'in' => 'O valor escolhido no campo :attribute é inválido.',
    'in_array' => 'O campo :attribute deve existir em :other.',
    'in_array_keys' => 'O campo :attribute deve conter pelo menos uma destas chaves: :values.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'ip' => 'O campo :attribute deve ser um endereço IP válido.',
    'ipv4' => 'O campo :attribute deve ser um endereço IPv4 válido.',
    'ipv6' => 'O campo :attribute deve ser um endereço IPv6 válido.',
    'json' => 'O campo :attribute deve ser um JSON válido.',
    'list' => 'O campo :attribute deve ser uma lista.',
    'lowercase' => 'O campo :attribute deve estar em letras minúsculas.',
    'lt' => [
        'array' => 'O campo :attribute deve ter menos de :value itens.',
        'file' => 'O campo :attribute deve ter menos de :value kilobytes.',
        'numeric' => 'O campo :attribute deve ser menor que :value.',
        'string' => 'O campo :attribute deve ter menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'O campo :attribute não pode ter mais de :value itens.',
        'file' => 'O campo :attribute deve ter :value kilobytes ou menos.',
        'numeric' => 'O campo :attribute deve ser menor ou igual a :value.',
        'string' => 'O campo :attribute deve ter :value caracteres ou menos.',
    ],
    'mac_address' => 'O campo :attribute deve ser um endereço MAC válido.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais de :max itens.',
        'file' => 'O campo :attribute não pode ter mais de :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
    ],
    'max_digits' => 'O campo :attribute não pode ter mais de :max dígitos.',
    'mimes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'mimetypes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
        'file' => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute não pode ser menor que :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'min_digits' => 'O campo :attribute deve ter pelo menos :min dígitos.',
    'missing' => 'O campo :attribute não deve ser enviado.',
    'missing_if' => 'O campo :attribute não deve ser enviado quando :other for :value.',
    'missing_unless' => 'O campo :attribute não deve ser enviado, a menos que :other seja :value.',
    'missing_with' => 'O campo :attribute não deve ser enviado quando :values estiver presente.',
    'missing_with_all' => 'O campo :attribute não deve ser enviado quando :values estiverem presentes.',
    'multiple_of' => 'O campo :attribute deve ser um múltiplo de :value.',
    'not_in' => 'O valor escolhido no campo :attribute é inválido.',
    // Regra própria do BarberFlow (App\Rules\NotOnlySpaces).
    'not_only_spaces' => 'O campo :attribute não pode ter só espaços.',
    'not_regex' => 'O formato do campo :attribute é inválido.',
    'numeric' => 'O campo :attribute deve ser um número.',
    'password' => [
        'letters' => 'O campo :attribute deve conter pelo menos uma letra.',
        'mixed' => 'O campo :attribute deve conter pelo menos uma letra maiúscula e uma minúscula.',
        'numbers' => 'O campo :attribute deve conter pelo menos um número.',
        'symbols' => 'O campo :attribute deve conter pelo menos um símbolo.',
        'uncompromised' => 'O valor informado no campo :attribute apareceu em um vazamento de dados. Escolha outro valor para o campo :attribute.',
    ],
    'present' => 'O campo :attribute deve estar presente.',
    'present_if' => 'O campo :attribute deve estar presente quando :other for :value.',
    'present_unless' => 'O campo :attribute deve estar presente, a menos que :other seja :value.',
    'present_with' => 'O campo :attribute deve estar presente quando :values estiver presente.',
    'present_with_all' => 'O campo :attribute deve estar presente quando :values estiverem presentes.',
    'prohibited' => 'O campo :attribute não é permitido.',
    'prohibited_if' => 'O campo :attribute não é permitido quando :other for :value.',
    'prohibited_if_accepted' => 'O campo :attribute não é permitido quando :other for aceito.',
    'prohibited_if_declined' => 'O campo :attribute não é permitido quando :other for recusado.',
    'prohibited_unless' => 'O campo :attribute não é permitido, a menos que :other esteja em :values.',
    'prohibits' => 'O campo :attribute impede o envio de :other.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'required_array_keys' => 'O campo :attribute deve conter itens para: :values.',
    'required_if' => 'O campo :attribute é obrigatório quando :other for :value.',
    'required_if_accepted' => 'O campo :attribute é obrigatório quando :other for aceito.',
    'required_if_declined' => 'O campo :attribute é obrigatório quando :other for recusado.',
    'required_unless' => 'O campo :attribute é obrigatório, a menos que :other esteja em :values.',
    'required_with' => 'O campo :attribute é obrigatório quando :values estiver presente.',
    'required_with_all' => 'O campo :attribute é obrigatório quando :values estiverem presentes.',
    'required_without' => 'O campo :attribute é obrigatório quando :values não estiver presente.',
    'required_without_all' => 'O campo :attribute é obrigatório quando nenhum destes estiver presente: :values.',
    'same' => 'Os campos :attribute e :other devem ser iguais.',
    'size' => [
        'array' => 'O campo :attribute deve conter :size itens.',
        'file' => 'O campo :attribute deve ter :size kilobytes.',
        'numeric' => 'O campo :attribute deve ser :size.',
        'string' => 'O campo :attribute deve ter :size caracteres.',
    ],
    'starts_with' => 'O campo :attribute deve começar com um destes valores: :values.',
    'string' => 'O campo :attribute deve ser um texto.',
    'timezone' => 'O campo :attribute deve ser um fuso horário válido.',
    'unique' => 'O valor do campo :attribute já está em uso.',
    'uploaded' => 'Não foi possível enviar o campo :attribute.',
    'uppercase' => 'O campo :attribute deve estar em letras maiúsculas.',
    'url' => 'O campo :attribute deve ser uma URL válida.',
    'ulid' => 'O campo :attribute deve ser um ULID válido.',
    'uuid' => 'O campo :attribute deve ser um UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensagens por campo
    |--------------------------------------------------------------------------
    |
    | Formato "campo.regra". Vale para qualquer recurso que tenha o campo.
    |
    */

    'custom' => [
        'email' => [
            'unique' => 'Este e-mail já está cadastrado.',
            'not_regex' => 'O domínio barberflow.invalid é reservado para cadastros excluídos. Use outro e-mail.',
            // O cliente editando o próprio cadastro (ClientController::update).
            'missing' => 'O campo e-mail não é permitido.',
        ],
        // Mesmo máximo do ServiceController (decimal 8,2). Se um mudar, mude o outro.
        'price' => [
            'max' => 'O campo preço não pode ser maior que R$ 999.999,99.',
        ],
        // Valor do pagamento (PaymentController), também decimal 8,2.
        'amount' => [
            'max' => 'O campo valor não pode ser maior que R$ 999.999,99.',
            'decimal' => 'O campo valor pode ter no máximo 2 casas decimais.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nomes dos campos
    |--------------------------------------------------------------------------
    |
    | Trocam o :attribute das mensagens pelo nome do campo em português.
    |
    */

    'attributes' => [
        'name' => 'nome',
        'email' => 'e-mail',
        'phone' => 'telefone',
        'duration' => 'duração',
        'price' => 'preço',
        'password' => 'senha',
        'password_confirmation' => 'confirmação da senha',
        'current_password' => 'senha atual',
        'device_name' => 'nome do dispositivo',
        'token' => 'código do link',
        'client_id' => 'cliente',
        'barber_id' => 'barbeiro',
        'service_id' => 'serviço',
        'date' => 'data',
        'from' => 'data inicial',
        'to' => 'data final',
        'start_time' => 'horário',
        'appointment_id' => 'agendamento',
        'amount' => 'valor',
        'method' => 'forma de pagamento',
        'notes' => 'observações',
        'without_payment' => 'sem pagamento',
        'use_loyalty_reward' => 'prêmio de fidelidade',
        'page' => 'página',
        'per_page' => 'itens por página',
        'search' => 'busca',
    ],

];
