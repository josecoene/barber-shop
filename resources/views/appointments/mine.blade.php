@extends('layouts.app')

@use('App\Enums\AppointmentStatus')

@section('title', 'Meus horários')

@section('content')
    @php($cancelHours = (int) config('barberflow.client_cancel_min_hours'))

    {{--
        O pages.js (initMyAppointments) lista os horários do cliente pela API. A janela de cancelamento
        vem da config só para a tela esconder o botão a tempo: quem decide é a API.
    --}}
    <div data-my-appointments
        data-cancel-min-hours="{{ $cancelHours }}"
        data-statuses="{{ json_encode(AppointmentStatus::catalog()) }}"
        data-booking-url="{{ route('booking.create') }}">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Meus horários</h1>
            <a class="btn btn-primary" href="{{ route('booking.create') }}">Agendar horário</a>
        </div>

        <div data-alerts></div>
        <p class="visually-hidden" role="status" aria-live="polite" data-live></p>

        <section class="mb-4" aria-labelledby="my-upcoming-title">
            <h2 class="h5" id="my-upcoming-title" tabindex="-1">Próximos</h2>
            <p class="small text-body-secondary">
                Dá para cancelar aqui até {{ $cancelHours }} {{ $cancelHours === 1 ? 'hora' : 'horas' }} antes do horário.
                Depois disso, fale com a barbearia.
            </p>
            <div data-upcoming>
                <p class="text-body-secondary">Carregando...</p>
            </div>
        </section>

        <section aria-labelledby="my-past-title">
            <h2 class="h5" id="my-past-title">Passados e cancelados</h2>
            <div data-past>
                <p class="text-body-secondary">Carregando...</p>
            </div>
        </section>

        <div class="modal fade" tabindex="-1" aria-labelledby="my-cancel-title" aria-hidden="true" data-cancel-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="my-cancel-title">Cancelar horário</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div data-cancel-alerts></div>
                        <p class="mb-0" data-cancel-text></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-cancel-back>Voltar</button>
                        <button type="button" class="btn btn-danger" data-confirm-cancel>Cancelar horário</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
