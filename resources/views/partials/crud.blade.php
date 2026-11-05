{{-- Tela de cadastro genérica. Parâmetros: $resource, $title, $singular, $columns, $fields --}}
<div data-crud data-resource="{{ $resource }}" data-singular="{{ $singular }}">
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0">{{ $title }}</h1>
        <button type="button" class="btn btn-primary" data-action="create">Novo {{ $singular }}</button>
    </div>

    <div data-alerts></div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
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
