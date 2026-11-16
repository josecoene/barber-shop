{{-- Campos de barbeiro e cliente: name, email, phone (mesmas regras na API) --}}
<div class="mb-3">
    <label for="{{ $resource }}-name" class="form-label">Nome<span class="text-danger" aria-hidden="true"> *</span></label>
    <input type="text" class="form-control" id="{{ $resource }}-name" name="name" maxlength="255" autocomplete="off"
        aria-required="true">
    <div class="invalid-feedback" id="{{ $resource }}-name-error" data-error-for="name"></div>
</div>

<div class="mb-3">
    <label for="{{ $resource }}-email" class="form-label">E-mail<span class="text-danger" aria-hidden="true"> *</span></label>
    <input type="email" class="form-control" id="{{ $resource }}-email" name="email" maxlength="255" autocomplete="off"
        aria-required="true">
    <div class="invalid-feedback" id="{{ $resource }}-email-error" data-error-for="email"></div>
</div>

<div class="mb-1">
    <label for="{{ $resource }}-phone" class="form-label">Telefone <span class="text-body-secondary">(opcional)</span></label>
    <input type="tel" class="form-control" id="{{ $resource }}-phone" name="phone" maxlength="20" autocomplete="off">
    <div class="invalid-feedback" id="{{ $resource }}-phone-error" data-error-for="phone"></div>
</div>
