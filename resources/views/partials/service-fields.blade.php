{{-- Campos de serviço: name, duration (minutos), price, status (mesmas regras na API) --}}
<div class="mb-3">
    <label for="services-name" class="form-label">Nome<span class="text-danger" aria-hidden="true"> *</span></label>
    <input type="text" class="form-control" id="services-name" name="name" maxlength="255" autocomplete="off"
        aria-required="true">
    <div class="invalid-feedback" id="services-name-error" data-error-for="name"></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6">
        <label for="services-duration" class="form-label">Duração (min)<span class="text-danger" aria-hidden="true"> *</span></label>
        <input type="number" class="form-control" id="services-duration" name="duration" min="5" max="480" step="1"
            aria-required="true" aria-describedby="services-duration-hint">
        <div class="form-text" id="services-duration-hint">De 5 a 480 minutos.</div>
        <div class="invalid-feedback" id="services-duration-error" data-error-for="duration"></div>
    </div>
    <div class="col-sm-6">
        <label for="services-price" class="form-label">Preço (R$)<span class="text-danger" aria-hidden="true"> *</span></label>
        <input type="number" class="form-control" id="services-price" name="price" min="0" max="999999.99" step="0.01"
            aria-required="true">
        <div class="invalid-feedback" id="services-price-error" data-error-for="price"></div>
    </div>
</div>

<div class="mb-1">
    <label for="services-status" class="form-label">Status</label>
    <select class="form-select" id="services-status" name="status">
        <option value="active">Ativo</option>
        <option value="inactive">Inativo</option>
    </select>
    <div class="invalid-feedback" id="services-status-error" data-error-for="status"></div>
</div>
