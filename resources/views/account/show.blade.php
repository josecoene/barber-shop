@extends('layouts.app')

@section('title', 'Minha conta')

@section('content')
    @php($user = auth()->user())

    <h1 class="h3 mb-3">Minha conta</h1>

    <div class="row g-4">
        <div class="col-lg-5">
            <section class="card shadow-sm" aria-labelledby="account-data-title">
                <div class="card-body">
                    <h2 class="h5 mb-3" id="account-data-title">Seus dados</h2>

                    <dl class="mb-0">
                        <dt>Nome</dt>
                        <dd class="text-break">{{ $user->name }}</dd>

                        <dt>E-mail</dt>
                        <dd class="text-break">@include('partials.email-text', ['email' => $user->email])</dd>

                        <dt>Perfil</dt>
                        <dd>{{ $user->role?->label() }}</dd>

                        <dt>Situação do e-mail</dt>
                        <dd class="mb-0">
                            @if ($user->hasVerifiedEmail())
                                <span class="badge text-bg-success">Confirmado</span>
                                em {{ $user->email_verified_at->format('d/m/Y') }}
                            @else
                                <span class="badge text-bg-warning">Não confirmado</span>
                                <a href="{{ route('verification.notice') }}">Confirmar agora</a>
                            @endif
                        </dd>
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-7">
            {{-- O pages.js manda este formulário para PUT /api/user/password. --}}
            <section class="card shadow-sm" aria-labelledby="account-password-title" data-password-change>
                <div class="card-body">
                    <h2 class="h5 mb-3" id="account-password-title">Trocar a senha</h2>

                    <div data-alerts></div>

                    <form novalidate data-password-form>
                        {{-- Sem name, não vai para a API: só diz ao gerenciador de senhas de qual conta é a senha nova. --}}
                        <input type="email" class="d-none" value="{{ $user->email }}" autocomplete="username" readonly tabindex="-1" aria-hidden="true">

                        <div class="mb-3">
                            <label for="account-current-password" class="form-label">Senha atual<span class="text-danger" aria-hidden="true"> *</span></label>
                            <input type="password" class="form-control" id="account-current-password" name="current_password"
                                autocomplete="current-password" aria-required="true">
                            <div class="invalid-feedback" id="account-current-password-error" data-error-for="current_password"></div>
                        </div>

                        <div class="mb-3">
                            <label for="account-password" class="form-label">Senha nova<span class="text-danger" aria-hidden="true"> *</span></label>
                            <input type="password" class="form-control" id="account-password" name="password"
                                autocomplete="new-password" aria-required="true" aria-describedby="account-password-hint">
                            <div class="form-text" id="account-password-hint">Mínimo de 8 caracteres.</div>
                            <div class="invalid-feedback" id="account-password-error" data-error-for="password"></div>
                        </div>

                        <div class="mb-3">
                            <label for="account-password-confirmation" class="form-label">Confirme a senha nova<span class="text-danger" aria-hidden="true"> *</span></label>
                            <input type="password" class="form-control" id="account-password-confirmation" name="password_confirmation"
                                autocomplete="new-password" aria-required="true">
                            <div class="invalid-feedback" id="account-password-confirmation-error" data-error-for="password_confirmation"></div>
                        </div>

                        <p class="small text-body-secondary">
                            Depois da troca, quem estiver conectado à sua conta em outro aparelho vai precisar entrar de novo.
                        </p>

                        <button type="submit" class="btn btn-primary" data-submit>Trocar a senha</button>
                    </form>
                </div>
            </section>
        </div>
    </div>
@endsection
