@extends('layouts.app')

@use('App\Enums\AppointmentStatus')

@section('title', 'Agenda')

@section('content')
    @php
        // O barbeiro vê só a coluna dele; o admin vê a de todos os barbeiros.
        $user = auth()->user();
        $ownBarber = $user->isBarber() ? $user->barber : null;
    @endphp

    {{--
        O pages.js (initSchedule) monta a agenda com a API. Daqui ele lê o expediente
        (config/barberflow.php) e as regras de cada status (AppointmentStatus::catalog()),
        para não repetir nenhum dos dois no JS.
    --}}
    <div data-schedule
        data-opening="{{ config('barberflow.business.opening') }}"
        data-closing="{{ config('barberflow.business.closing') }}"
        data-slot-minutes="{{ config('barberflow.business.slot_minutes') }}"
        data-statuses="{{ json_encode(AppointmentStatus::catalog()) }}"
        data-create-url="{{ route('appointments.create') }}"
        @if ($ownBarber) data-own-barber-id="{{ $ownBarber->id }}" data-own-barber-name="{{ $ownBarber->name }}" @endif>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Agenda</h1>
            <a class="btn btn-primary" href="{{ route('appointments.create') }}" data-new-appointment>Novo agendamento</a>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <div class="btn-group" role="group" aria-label="Escolher o dia">
                <button type="button" class="btn btn-outline-secondary" data-day-previous>Dia anterior</button>
                <button type="button" class="btn btn-outline-secondary" data-day-today>Hoje</button>
                <button type="button" class="btn btn-outline-secondary" data-day-next>Próximo dia</button>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="schedule-date" class="col-form-label">Data</label>
                <input type="date" class="form-control w-auto" id="schedule-date" data-date>
            </div>
        </div>

        <h2 class="h5 mb-3" data-day-title></h2>

        <div data-alerts></div>
        {{-- Avisa o leitor de tela quando a agenda carrega e quando um status muda. --}}
        <p class="visually-hidden" role="status" aria-live="polite" data-live></p>

        <div data-board>
            <p class="text-body-secondary" data-board-message>Carregando a agenda...</p>

            {{-- Da largura de tablet para cima: uma coluna por barbeiro. --}}
            <div class="d-none d-md-block">
                <div class="card shadow-sm" data-grid-card hidden>
                    <div class="schedule-scroller" data-grid></div>
                </div>
            </div>

            {{-- No celular: uma lista por barbeiro. --}}
            <div class="d-md-none" data-list></div>

            {{-- Cancelados e faltas não ocupam o horário, então ficam fora das colunas. --}}
            <div class="d-none d-md-block mt-4" data-inactive></div>
        </div>

        <div class="modal fade" tabindex="-1" aria-labelledby="appointment-modal-title" aria-hidden="true" data-appointment-modal>
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="appointment-modal-title" data-modal-title>Agendamento</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div data-modal-alerts></div>
                        <dl class="row mb-0" data-modal-details></dl>
                        <p class="small text-body-secondary mt-2 mb-0" data-modal-hint hidden></p>
                    </div>
                    <div class="modal-footer" data-modal-actions></div>
                    <div class="modal-footer" data-modal-confirm hidden>
                        <p class="w-100 mb-1" id="appointment-confirm-text" data-modal-confirm-text></p>
                        <button type="button" class="btn btn-outline-secondary" aria-describedby="appointment-confirm-text" data-modal-confirm-back>Voltar</button>
                        <button type="button" class="btn btn-danger" aria-describedby="appointment-confirm-text" data-modal-confirm-yes></button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Atendimento concluído sem pagamento: o "Registrar pagamento" do detalhe abre o mesmo modal da tela de Pagamentos. --}}
        @include('partials.payment-modal')
    </div>
@endsection
