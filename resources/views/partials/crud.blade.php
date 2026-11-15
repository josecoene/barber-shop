{{--
    Tela de cadastro genérica. Parâmetros: $resource, $title, $singular, $plural, $searchLabel, $columns, $fields
    e $canDelete (opcional, padrão true: sem permissão, a tabela e a lista do celular ficam sem o botão Excluir; a API também recusa).
--}}
<div data-crud data-resource="{{ $resource }}" data-singular="{{ $singular }}" data-plural="{{ $plural }}"
    data-can-delete="{{ ($canDelete ?? true) ? 'true' : 'false' }}">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">{{ $title }}</h1>
        <button type="button" class="btn btn-primary" data-action="create">Novo {{ $singular }}</button>
    </div>

    <div data-alerts></div>

    <div class="row mb-3">
        <div class="col-sm-8 col-lg-5">
            <label for="{{ $resource }}-search" class="form-label">{{ $searchLabel }}</label>
            <input type="search" class="form-control" id="{{ $resource }}-search" maxlength="100" autocomplete="off"
                aria-controls="{{ $resource }}-list {{ $resource }}-table" data-search>
        </div>
    </div>

    <div class="card shadow-sm">
        {{--
            No celular: um item por cadastro, com o nome em cima, cada dado embaixo dele com o nome da coluna
            e os botões no fim. Nos 390px, a tabela só caberia partindo o nome e o e-mail no meio da palavra.
            A lista vem antes da tabela para ganhar os cantos arredondados do cartão (.card > .list-group:first-child).
        --}}
        <ul class="list-group list-group-flush border-bottom-0 d-md-none" id="{{ $resource }}-list"
            aria-label="{{ $title }} cadastrados, em ordem de nome" aria-describedby="{{ $resource }}-page-status" data-mobile-rows>
            <li class="list-group-item text-center text-body-secondary py-4">Carregando...</li>
        </ul>

        {{--
            Da largura de tablet para cima: a tabela. O position-relative prende nela o texto escondido para o
            leitor de tela (visually-hidden, que é position: absolute): sem ele, numa tabela que rola para o
            lado, esse texto ficaria fora da área de rolagem e faria a página inteira rolar.
        --}}
        <div class="table-responsive position-relative d-none d-md-block">
            {{-- aria-label em vez de <caption> escondido: o caption ocupa 1px e cria uma barra de rolagem na .table-responsive --}}
            <table class="table table-hover align-middle mb-0" id="{{ $resource }}-table"
                aria-label="{{ $title }} cadastrados, em ordem de nome" aria-describedby="{{ $resource }}-page-status">
                <thead class="table-light">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col">{{ $column }}</th>
                        @endforeach
                        <th scope="col" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody data-rows>
                    <tr>
                        <td colspan="{{ count($columns) + 1 }}" class="text-center text-body-secondary py-4">Carregando...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center" aria-label="Páginas de {{ mb_strtolower($title) }}">
            <p class="mb-0 small text-body-secondary" id="{{ $resource }}-page-status" role="status" aria-live="polite" data-page-status>Carregando...</p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-page-previous disabled>Anterior</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-page-next disabled>Próxima</button>
            </div>
        </nav>
    </div>

    <div class="modal fade" tabindex="-1" aria-labelledby="{{ $resource }}-form-title" aria-hidden="true" data-form-modal>
        <div class="modal-dialog">
            <form class="modal-content" novalidate data-form>
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="{{ $resource }}-form-title" data-form-title></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div data-form-alerts></div>
                    <p class="small text-body-secondary">Campos com <span class="text-danger">*</span> são obrigatórios.</p>
                    @include($fields)
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" data-submit>Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" tabindex="-1" aria-labelledby="{{ $resource }}-delete-title" aria-hidden="true" data-delete-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="{{ $resource }}-delete-title">Excluir {{ $singular }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    Tem certeza que deseja excluir <strong data-delete-name></strong>? Essa ação não pode ser desfeita.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" data-confirm-delete>Excluir</button>
                </div>
            </div>
        </div>
    </div>
</div>
