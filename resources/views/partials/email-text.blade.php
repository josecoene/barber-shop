@php
    /*
     * O e-mail ($email) com um <wbr> depois do @ e antes de cada ponto, os únicos lugares onde a linha pode
     * quebrar, como o emailText do resources/js/pages.js. Cada pedaço passa pelo e(), o mesmo escape que o
     * Blade usa no texto da tela, e o <wbr> só marca onde a linha pode quebrar. Quem inclui põe o text-break
     * em volta: um pedaço mais largo que a tela inteira ainda quebra no meio (o overflow-wrap dele), para a
     * página não rolar para o lado. Sai sem espaço antes e depois, para caber no meio de uma frase.
     */
    echo implode('<wbr>', array_map(e(...), preg_split('/(?<=@)|(?=\.)/', (string) $email, -1, PREG_SPLIT_NO_EMPTY)));
@endphp
