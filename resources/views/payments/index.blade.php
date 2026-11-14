@extends('layouts.app')

@use('App\Enums\PaymentMethod')
@use('App\Enums\PaymentStatus')

@section('title', 'Pagamentos')

@section('content')
    @php
        // O barbeiro vê só os pagamentos da agenda dele (a API filtra), então a tela fica sem o filtro de barbeiro.
        $user = auth()->user();
        $ownBarber = $user->isBarber() ? $user->barber : null;
        // Corrigir e estornar são só do admin (PaymentPolicy): para o barbeiro, os botões nem aparecem.
        $canManage = Gate::allows('access-admin');
    @endphp

    {{--
        O pages.js (initPayments) monta as listas com a API: os atendimentos concluídos sem pagamento
        (GET /api/appointments?without_payment=1) e os pagamentos (GET /api/payments), com os totais do
        filtro. As regras de cada status (PaymentStatus::catalog()) vêm daqui, para o JS não repetir as
        transições.
    --}}
    <div data-payments
        data-payment-statuses="{{ json_encode(PaymentStatus::catalog()) }}"
        data-can-manage="{{ $canManage ? 'true' : 'false' }}"
        @if ($ownBarber) data-own-barber-id="{{ $ownBarber->id }}" @endif>
        <h1 class="h3 mb-3">Pagamentos</h1>

        <div data-alerts></div>

        <section class="card shadow-sm mb-4" aria-labelledby="payments-awaiting-title">
            <div class="card-header bg-body">
                <h2 class="h5 mb-1" id="payments-awaiting-title" tabindex="-1">Atendimentos sem pagamento</h2>
                <p class="small text-body-secondary mb-0">
                    Atendimentos concluídos que ainda não têm pagamento registrado, do mais antigo para o mais novo.
                </p>
            </div>
            <div data-awaiting>
                <p class="text-body-secondary px-3 py-4 mb-0">Carregando...</p>
            </div>
            <nav class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center" aria-label="Páginas de atendimentos sem pagamento">
                <p class="mb-0 small text-body-secondary" role="status" aria-live="polite" data-awaiting-status>Carregando...</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-awaiting-previous disabled>Anterior</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-awaiting-next disabled>Próxima</button>
                </div>
            </nav>
        </section>

        <section aria-labelledby="payments-list-title">
            <h2 class="h5 mb-3" id="payments-list-title" tabindex="-1">Pagamentos registrados</h2>

            {{-- Os filtros valem para a tabela e para os totais. Mudou um, a lista volta para a página 1. --}}
            <form class="row g-2 mb-3" novalidate data-filters aria-label="Filtros dos pagamentos">
                <div class="col-12" data-filter-alerts></div>

                <div class="col-6 col-md-4 col-lg-2">
                    <label for="payments-from" class="form-label">De</label>
                    <input type="date" class="form-control" id="payments-from" name="from" aria-describedby="payments-period-hint">
                    <div class="invalid-feedback" id="payments-from-error" data-error-for="from"></div>
                </div>

                <div class="col-6 col-md-4 col-lg-2">
                    <label for="payments-to" class="form-label">Até</label>
                    <input type="date" class="form-control" id="payments-to" name="to" aria-describedby="payments-period-hint">
                    <div class="invalid-feedback" id="payments-to-error" data-error-for="to"></div>
                </div>

                <div class="col-6 col-md-4 col-lg-2">
                    <label for="payments-method" class="form-label">Forma</label>
                    <select class="form-select" id="payments-method" name="method">
                        <option value="">Todas</option>
                        @foreach (PaymentMethod::catalog() as $value => $method)
                            <option value="{{ $value }}">{{ $method['label'] }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" id="payments-method-error" data-error-for="method"></div>
                </div>

                <div class="col-6 col-md-4 col-lg-2">
                    <label for="payments-status" class="form-label">Status</label>
                    <select class="form-select" id="payments-status" name="status">
                        <option value="">Todos</option>
                        @foreach (PaymentStatus::catalog() as $value => $status)
                            <option value="{{ $value }}">{{ $status['label'] }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" id="payments-status-error" data-error-for="status"></div>
                </div>

                @unless ($ownBarber)
                    <div class="col-12 col-md-4 col-lg-2">
                        <label for="payments-barber" class="form-label">Barbeiro</label>
                        <select class="form-select" id="payments-barber" name="barber_id">
                            <option value="">Todos</option>
                        </select>
                        <div class="invalid-feedback" id="payments-barber-error" data-error-for="barber_id"></div>
                    </div>
                @endunless

                <div class="col-12 col-md-4 col-lg-2 d-flex flex-column justify-content-end">
                    <button type="button" class="btn btn-outline-secondary" data-clear-filters>Limpar filtros</button>
                </div>

                <p class="col-12 form-text mt-1 mb-0" id="payments-period-hint">O período é pela data do atendimento, com os dois dias incluídos.</p>
            </form>

            <div class="card shadow-sm">
                <div class="card-body border-bottom">
                    <h3 class="h6 text-body-secondary mb-2">Totais com os filtros</h3>
                    <dl class="row row-cols-1 row-cols-sm-3 g-2 mb-0" data-totals>
                        <div class="col text-body-secondary">Carregando...</div>
                    </dl>
                </div>

                {{-- Da largura de tablet para cima: a tabela. --}}
                <div class="table-responsive d-none d-md-block">
                    {{-- aria-labelledby em vez de <caption> escondido: o caption ocupa 1px e cria uma barra de rolagem na .table-responsive --}}
                    <table class="table table-hover align-middle mb-0" id="payments-table"
                        aria-labelledby="payments-list-title" aria-describedby="payments-page-status">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Atendimento</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Valor</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody data-rows>
                            <tr>
                                <td colspan="5" class="text-center text-body-secondary py-4">Carregando...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- No celular: um item por pagamento, com os botões embaixo (a tabela não caberia nos 390px). --}}
                <ul class="list-group list-group-flush d-md-none" aria-labelledby="payments-list-title" aria-describedby="payments-page-status" data-mobile-rows>
                    <li class="list-group-item text-center text-body-secondary py-4">Carregando...</li>
                </ul>

                <nav class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center" aria-label="Páginas de pagamentos">
                    <p class="mb-0 small text-body-secondary" id="payments-page-status" role="status" aria-live="polite" data-page-status>Carregando...</p>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-page-previous disabled>Anterior</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-page-next disabled>Próxima</button>
                    </div>
                </nav>
            </div>
        </section>

        @include('partials.payment-modal')

        {{-- Confirmação de "Marcar como pago" e "Estornar", aqui mesmo: nada de confirm() do navegador. --}}
        <div class="modal fade" tabindex="-1" aria-labelledby="payment-confirm-title" aria-hidden="true" data-payment-confirm-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="payment-confirm-title" data-payment-confirm-title>Confirmar</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div data-payment-confirm-alerts></div>
                        <p class="mb-0" id="payment-confirm-text" data-payment-confirm-text></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-payment-confirm-back>Voltar</button>
                        <button type="button" class="btn btn-danger" aria-describedby="payment-confirm-text" data-payment-confirm-yes></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
