{{--
    Formulário de agendamento, ligado à API pelo pages.js (initAppointmentForm). Parâmetros:
    - $mode: 'staff' no Agendar da barbearia (com cliente e observações; também remarca, com
      ?appointment={id}) ou 'client' no Agendar do cliente, que agenda para si;
    - $heading, $backUrl e $backLabel: título e link de voltar;
    - $successUrl: para onde vai depois de salvar (a agenda, no dia do agendamento, ou Meus horários).
    Com os campos preenchidos, os horários livres do barbeiro viram botões.
--}}
@use('App\Enums\AppointmentStatus')

@php
    $isStaff = $mode === 'staff';
    $user = auth()->user();
    // O barbeiro agenda só na própria agenda: o campo de barbeiro vem fixo com ele.
    $ownBarber = $isStaff && $user->isBarber() ? $user->barber : null;
@endphp
<div data-appointment-form data-mode="{{ $mode }}"
    data-business-days="{{ implode(',', config('barberflow.business.days')) }}"
    data-booking-days-ahead="{{ config('barberflow.booking_days_ahead') }}"
    data-statuses="{{ json_encode(AppointmentStatus::catalog()) }}"
    data-success-url="{{ $successUrl }}"
    @if ($ownBarber) data-own-barber-id="{{ $ownBarber->id }}" @endif>
    <h1 class="h3 mb-3" data-form-heading>{{ $heading }}</h1>

    <div data-alerts></div>
    {{-- Avisa o leitor de tela quando as listas e os horários livres carregam. --}}
    <p class="visually-hidden" role="status" aria-live="polite" data-live></p>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-body p-3 p-sm-4">
                    <form novalidate data-form>
                        <div data-form-alerts></div>
                        <p class="small text-body-secondary">Campos com <span class="text-danger">*</span> são obrigatórios.</p>

                        <div class="row g-3">
                            @if ($isStaff)
                                <div class="col-md-6">
                                    <label for="appointment-client" class="form-label">Cliente<span class="text-danger" aria-hidden="true"> *</span></label>
                                    <select class="form-select" id="appointment-client" name="client_id" aria-required="true">
                                        <option value="">Carregando...</option>
                                    </select>
                                    <div class="form-text" id="appointment-client-hint" data-client-hint hidden>O cliente não muda na remarcação.</div>
                                    <div class="invalid-feedback" id="appointment-client-error" data-error-for="client_id"></div>
                                </div>
                            @endif

                            <div class="col-md-6">
                                <label for="appointment-barber" class="form-label">Barbeiro<span class="text-danger" aria-hidden="true"> *</span></label>
                                <select class="form-select" id="appointment-barber" name="barber_id" aria-required="true"
                                    @if ($ownBarber) disabled aria-describedby="appointment-barber-hint" @endif>
                                    @if ($ownBarber)
                                        <option value="{{ $ownBarber->id }}" selected>{{ $ownBarber->name }}</option>
                                    @else
                                        <option value="">Carregando...</option>
                                    @endif
                                </select>
                                @if ($ownBarber)
                                    <div class="form-text" id="appointment-barber-hint">Você agenda na sua própria agenda.</div>
                                @endif
                                <div class="invalid-feedback" id="appointment-barber-error" data-error-for="barber_id"></div>
                            </div>

                            <div class="col-md-6">
                                <label for="appointment-service" class="form-label">Serviço<span class="text-danger" aria-hidden="true"> *</span></label>
                                <select class="form-select" id="appointment-service" name="service_id" aria-required="true">
                                    <option value="">Carregando...</option>
                                </select>
                                <div class="invalid-feedback" id="appointment-service-error" data-error-for="service_id"></div>
                            </div>

                            <div class="col-md-6">
                                <label for="appointment-date" class="form-label">Data<span class="text-danger" aria-hidden="true"> *</span></label>
                                <input type="date" class="form-control" id="appointment-date" name="date" aria-required="true">
                                <div class="invalid-feedback" id="appointment-date-error" data-error-for="date"></div>
                            </div>

                            <div class="col-12">
                                {{-- Um botão de rádio por horário livre (pages.js), com o visual de botão do Bootstrap. --}}
                                <fieldset class="slot-group" role="radiogroup" tabindex="-1" aria-labelledby="appointment-slots-legend"
                                    aria-describedby="appointment-slots-status" aria-required="true" data-error-group="start_time">
                                    <legend class="form-label fs-6 mb-1" id="appointment-slots-legend">Horário<span class="text-danger" aria-hidden="true"> *</span></legend>
                                    <p class="form-text mt-0 mb-2" id="appointment-slots-status" data-slots-status>Carregando...</p>
                                    <div class="d-flex flex-wrap gap-2" data-slots></div>
                                </fieldset>
                                <div class="invalid-feedback" id="appointment-start-time-error" data-error-for="start_time"></div>
                            </div>

                            @if ($isStaff)
                                <div class="col-12">
                                    <label for="appointment-notes" class="form-label">Observações <span class="text-body-secondary">(opcional)</span></label>
                                    <textarea class="form-control" id="appointment-notes" name="notes" rows="2" maxlength="1000"
                                        aria-describedby="appointment-notes-hint"></textarea>
                                    <div class="form-text" id="appointment-notes-hint">Até 1000 caracteres. O cliente também vê este texto em Meus horários.</div>
                                    <div class="invalid-feedback" id="appointment-notes-error" data-error-for="notes"></div>
                                </div>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-4">
                            <a class="btn btn-outline-secondary" href="{{ $backUrl }}" data-back-link>{{ $backLabel }}</a>
                            <button type="submit" class="btn btn-primary" data-submit>Agendar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <section class="card shadow-sm" aria-labelledby="appointment-summary-title">
                <div class="card-body">
                    <h2 class="h6 mb-3" id="appointment-summary-title">Resumo</h2>
                    <div data-summary></div>
                </div>
            </section>
        </div>
    </div>
</div>
