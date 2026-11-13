{{--
    Campo das telas de conta (login, cadastro, senha), que mandam o formulário para o servidor.
    Parâmetros: $id, $name, $label, $type (padrão text), $autocomplete, $required (padrão false),
    $value (padrão vazio; senha nunca volta preenchida), $maxlength e $hint (dica embaixo do campo).
    O erro de validação do campo (@error) aparece embaixo dele, ligado pelo aria-describedby.
--}}
@php
    $type ??= 'text';
    $required ??= false;
    // Só texto volta para o campo: um old() que veio como lista (ex.: email[]=x) quebraria a página.
    $value = isset($value) && is_scalar($value) ? (string) $value : null;
    $autocomplete ??= null;
    $maxlength ??= null;
    $hint ??= null;

    $describedBy = implode(' ', array_filter([
        $errors->has($name) ? "{$id}-error" : null,
        $hint ? "{$id}-hint" : null,
    ]));
@endphp
<div class="mb-3">
    <label for="{{ $id }}" class="form-label">
        {{ $label }}@if ($required)<span class="text-danger" aria-hidden="true"> *</span>@else <span class="text-body-secondary">(opcional)</span>@endif
    </label>
    <input type="{{ $type }}" class="form-control @error($name) is-invalid @enderror" id="{{ $id }}" name="{{ $name }}"
        @if ($value !== null) value="{{ $value }}" @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($maxlength) maxlength="{{ $maxlength }}" @endif
        @if ($required) required aria-required="true" @endif
        @error($name) aria-invalid="true" @enderror
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @error($name)
        <div class="invalid-feedback" id="{{ $id }}-error">{{ $message }}</div>
    @enderror
    @if ($hint)
        <div class="form-text" id="{{ $id }}-hint">{{ $hint }}</div>
    @endif
</div>
