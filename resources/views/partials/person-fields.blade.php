{{-- Campos de barbeiro e cliente: name, email, phone (mesmas regras na API) --}}
<div class="mb-3">
    <label for="{{ $resource }}-name" class="form-label">Nome</label>
    <input type="text" class="form-control" id="{{ $resource }}-name" name="name" maxlength="255" autocomplete="off">
    <div class="invalid-feedback" data-error-for="name"></div>
</div>

<div class="mb-3">
    <label for="{{ $resource }}-email" class="form-label">E-mail</label>
    <input type="email" class="form-control" id="{{ $resource }}-email" name="email" autocomplete="off">
    <div class="invalid-feedback" data-error-for="email"></div>
</div>

<div class="mb-1">
    <label for="{{ $resource }}-phone" class="form-label">Telefone <span class="text-body-secondary">(opcional)</span></label>
    <input type="tel" class="form-control" id="{{ $resource }}-phone" name="phone" maxlength="20" autocomplete="off">
    <div class="invalid-feedback" data-error-for="phone"></div>
</div>
