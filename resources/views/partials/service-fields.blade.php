{{-- Campos de serviço: name, duration (minutos), price, status (mesmas regras na API) --}}
<div class="mb-3">
    <label for="services-name" class="form-label">Nome</label>
    <input type="text" class="form-control" id="services-name" name="name" maxlength="255" autocomplete="off">
    <div class="invalid-feedback" data-error-for="name"></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6">
        <label for="services-duration" class="form-label">Duração (min)</label>
        <input type="number" class="form-control" id="services-duration" name="duration" min="1" step="1">
        <div class="invalid-feedback" data-error-for="duration"></div>
    </div>
    <div class="col-6">
        <label for="services-price" class="form-label">Preço (R$)</label>
        <input type="number" class="form-control" id="services-price" name="price" min="0" step="0.01">
        <div class="invalid-feedback" data-error-for="price"></div>
    </div>
</div>

<div class="mb-1">
    <label for="services-status" class="form-label">Status</label>
    <select class="form-select" id="services-status" name="status">
        <option value="active">Ativo</option>
        <option value="inactive">Inativo</option>
    </select>
    <div class="invalid-feedback" data-error-for="status"></div>
</div>
