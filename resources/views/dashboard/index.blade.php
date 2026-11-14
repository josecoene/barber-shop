@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        // Cartões de número: a chave é a que o pages.js (initDashboard) preenche.
        $tiles = [
            'revenue' => 'Faturamento',
            'appointments' => 'Atendimentos',
            'ticket' => 'Ticket médio',
            'no-show' => 'Taxa de faltas',
            'occupancy' => 'Ocupação',
        ];
    @endphp

    {{--
        O pages.js (initDashboard) busca os números em GET /api/dashboard e monta os cartões, as barras e
        as tabelas. A largura de cada barra vai por el.style, que a CSP permite. As definições de cada
        número ficam no App\Services\DashboardMetrics.
    --}}
    <div data-dashboard>
        <div class="d-flex flex-wrap column-gap-3 row-gap-1 align-items-baseline mb-3">
            <h1 class="h3 mb-0">Dashboard</h1>
            <p class="mb-0 text-body-secondary" data-period-title>Carregando...</p>
        </div>

        {{-- O período é o único filtro e fica acima de tudo que ele muda. --}}
        <div class="d-flex flex-wrap gap-3 align-items-start mb-3">
            <div class="btn-group" role="group" aria-label="Período">
                <input type="radio" class="btn-check" name="dashboard-period" id="dashboard-period-current" value="current" autocomplete="off" data-period-option checked>
                <label class="btn btn-outline-primary" for="dashboard-period-current">Mês atual</label>

                <input type="radio" class="btn-check" name="dashboard-period" id="dashboard-period-previous" value="previous" autocomplete="off" data-period-option>
                <label class="btn btn-outline-primary" for="dashboard-period-previous">Mês passado</label>

                <input type="radio" class="btn-check" name="dashboard-period" id="dashboard-period-custom" value="custom" autocomplete="off" data-period-option>
                <label class="btn btn-outline-primary" for="dashboard-period-custom">Personalizado</label>
            </div>

            {{-- O hidden fica no form, e o d-flex num div dentro: o display do d-flex (!important) venceria o hidden. --}}
            <form novalidate data-custom-period hidden aria-label="Período personalizado">
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    {{-- O "De" e o "Até" na frente do campo são só visuais: o label escondido é o nome do campo. --}}
                    <div>
                        <label for="dashboard-from" class="visually-hidden">De</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text" aria-hidden="true">De</span>
                            <input type="date" class="form-control" id="dashboard-from" name="from" aria-required="true">
                            <div class="invalid-feedback" id="dashboard-from-error" data-error-for="from"></div>
                        </div>
                    </div>
                    <div>
                        <label for="dashboard-to" class="visually-hidden">Até</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text" aria-hidden="true">Até</span>
                            <input type="date" class="form-control" id="dashboard-to" name="to" aria-required="true" aria-describedby="dashboard-period-hint">
                            <div class="invalid-feedback" id="dashboard-to-error" data-error-for="to"></div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Ver o período</button>
                    <p class="form-text w-100 mt-0 mb-0" id="dashboard-period-hint">Até 366 dias, com os dois dias incluídos.</p>
                </div>
            </form>
        </div>

        <div data-alerts></div>
        <p class="visually-hidden" role="status" aria-live="polite" data-live></p>

        <div class="dashboard-content" data-dashboard-content>
            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5 g-3 mb-4">
                @foreach ($tiles as $key => $label)
                    <div class="col">
                        <section class="card shadow-sm h-100" aria-labelledby="dashboard-tile-{{ $key }}">
                            <div class="card-body">
                                <h2 class="h6 fw-normal text-body-secondary mb-1" id="dashboard-tile-{{ $key }}">{{ $label }}</h2>
                                <p class="stat-value mb-1" data-tile-value="{{ $key }}"></p>
                                <p class="small text-body-secondary mb-0" data-tile-detail="{{ $key }}">Carregando...</p>
                            </div>
                        </section>
                    </div>
                @endforeach
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-7">
                    <section class="card shadow-sm h-100" aria-labelledby="dashboard-days-title">
                        <div class="card-body">
                            <h2 class="h5" id="dashboard-days-title" data-days-title>Faturamento por dia</h2>
                            <p class="small text-body-secondary">Pagamentos pagos, pela data do atendimento.</p>
                            <div data-days>
                                <p class="text-body-secondary mb-0">Carregando...</p>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="col-lg-5 d-flex flex-column gap-4">
                    <section class="card shadow-sm" aria-labelledby="dashboard-methods-title">
                        <div class="card-body">
                            <h2 class="h5" id="dashboard-methods-title">Faturamento por forma de pagamento</h2>
                            <p class="small text-body-secondary">Pagamentos pagos; pendentes e estornados ficam de fora.</p>
                            <div data-methods>
                                <p class="text-body-secondary mb-0">Carregando...</p>
                            </div>
                        </div>
                    </section>

                    <section class="card shadow-sm" aria-labelledby="dashboard-statuses-title">
                        <div class="card-body">
                            <h2 class="h5" id="dashboard-statuses-title">Atendimentos por status</h2>
                            <div data-statuses>
                                <p class="text-body-secondary mb-0">Carregando...</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    {{-- O flex-grow-0 deixa a tabela logo embaixo do título quando o cartão do lado é mais alto. --}}
                    <section class="card shadow-sm h-100" aria-labelledby="dashboard-services-title">
                        <div class="card-body flex-grow-0 pb-0">
                            <h2 class="h5" id="dashboard-services-title">Top 5 serviços</h2>
                            <p class="small text-body-secondary">Os que mais tiveram atendimentos concluídos, com o faturamento deles.</p>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" aria-labelledby="dashboard-services-title">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Serviço</th>
                                        <th scope="col" class="text-end">Concluídos</th>
                                        <th scope="col" class="text-end">Faturamento</th>
                                    </tr>
                                </thead>
                                <tbody data-services>
                                    <tr>
                                        <td colspan="3" class="text-center text-body-secondary py-4">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="col-lg-6">
                    <section class="card shadow-sm h-100" aria-labelledby="dashboard-barbers-title">
                        <div class="card-body flex-grow-0 pb-0">
                            <h2 class="h5" id="dashboard-barbers-title">Por barbeiro</h2>
                            <p class="small text-body-secondary">Atendimentos concluídos, faltas e faturamento de cada barbeiro.</p>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0" aria-labelledby="dashboard-barbers-title">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Barbeiro</th>
                                        <th scope="col" class="text-end">Concluídos</th>
                                        <th scope="col" class="text-end">Faltas</th>
                                        <th scope="col" class="text-end">Faturamento</th>
                                    </tr>
                                </thead>
                                <tbody data-barbers>
                                    <tr>
                                        <td colspan="4" class="text-center text-body-secondary py-4">Carregando...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
@endsection
