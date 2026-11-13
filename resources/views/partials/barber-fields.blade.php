{{-- Campos de barbeiro: os de pessoa (name, email, phone) e a senha do login, que só o admin manda (mesmas regras na API) --}}
@include('partials.person-fields')

<div class="mt-3 mb-1">
    <label for="barbers-password" class="form-label">Senha de acesso <span class="text-body-secondary">(opcional)</span></label>
    {{-- new-password: o navegador não preenche aqui a senha salva de quem está logado. --}}
    <input type="password" class="form-control" id="barbers-password" name="password" autocomplete="new-password"
        aria-describedby="barbers-password-hint">
    <div class="form-text" id="barbers-password-hint">
        Preencha para criar ou redefinir o acesso do barbeiro. Mínimo de 8 caracteres.
    </div>
    <div class="invalid-feedback" id="barbers-password-error" data-error-for="password"></div>
</div>
