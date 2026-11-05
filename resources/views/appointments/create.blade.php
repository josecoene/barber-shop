@extends('layouts.app')

@section('title', 'Agendar')

@section('content')
    <div data-booking>
        <h1 class="h3 mb-3">Novo agendamento</h1>

        <div class="alert alert-info" role="status">
            O agendamento online ainda é só visual: a API não tem a rota de agendamentos, então nada é salvo.
        </div>

        <div data-alerts></div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <form novalidate data-booking-form>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="booking-client" class="form-label">Cliente</label>
                                    <select class="form-select" id="booking-client" name="client_id" required>
                                        <option value="">Carregando...</option>
                                    </select>
                                    <div class="invalid-feedback">Escolha o cliente.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="booking-barber" class="form-label">Barbeiro</label>
                                    <select class="form-select" id="booking-barber" name="barber_id" required>
                                        <option value="">Carregando...</option>
                                    </select>
                                    <div class="invalid-feedback">Escolha o barbeiro.</div>
                                </div>

                                <div class="col-12">
                                    <label for="booking-service" class="form-label">Serviço</label>
                                    <select class="form-select" id="booking-service" name="service_id" required>
                                        <option value="">Carregando...</option>
                                    </select>
                                    <div class="invalid-feedback">Escolha o serviço.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="booking-date" class="form-label">Data</label>
                                    <input type="date" class="form-control" id="booking-date" name="date" required>
                                    <div class="invalid-feedback">Escolha uma data a partir de hoje.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="booking-time" class="form-label">Horário</label>
                                    <select class="form-select" id="booking-time" name="start_time" required></select>
                                    <div class="invalid-feedback">Escolha o horário.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 justify-content-end mt-4">
                                <a class="btn btn-outline-secondary" href="{{ route('appointments.index') }}">Cancelar</a>
                                <button type="submit" class="btn btn-primary">Agendar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Resumo</h2>
                        <div data-summary></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
