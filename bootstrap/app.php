<?php

use App\Exceptions\TooManyLoginAttemptsException;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // As telas chamam a API com o cookie da sessão: o Sanctum reconhece as telas pelo
        // Referer (SANCTUM_STATEFUL_DOMAINS) e aplica sessão e CSRF nessas chamadas.
        $middleware->statefulApi();

        // 'throttle:api' em todas as rotas da API. O limitador 'api' fica no AppServiceProvider.
        $middleware->throttleApi();

        // A sessão guarda um resumo da senha: se a senha muda (troca, redefinição ou o admin
        // redefinindo a do barbeiro), as outras sessões abertas caem no próximo clique, com
        // qualquer driver de sessão. Nas chamadas da API o Sanctum faz a mesma conferência.
        $middleware->authenticateSessions();

        // Quem já está logado e abre /login, /register etc. vai para a tela inicial do perfil.
        $middleware->redirectUsersTo(fn () => route('home'));

        // Hosts aceitos no cabeçalho Host (config/barberflow.php). O Laravel não confere no local nem nos testes.
        $middleware->trustHosts(at: fn () => config('barberflow.security.trusted_hosts'), subdomains: false);

        // Primeiro da fila, para os cabeçalhos de segurança saírem em toda resposta, inclusive nas de erro.
        $middleware->prepend(SecurityHeaders::class);

        // O verified do Laravel com a resposta da API em pt-BR.
        $middleware->alias(['verified' => EnsureEmailIsVerified::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erros sem dado pessoal no log, em dois casos:
        // - erro de banco: a mensagem da QueryException traz o SQL com os valores da consulta no lugar dos
        //   "?" (nome, e-mail e observações de quem está nela), e a do MySQL pode repetir um valor
        //   ("Duplicate entry 'x' for key ..."). O log fica com a conexão e o código do erro (o SQLSTATE e o
        //   do banco, como 23000 e 1062);
        // - falha no envio de e-mail (confirmação de conta, "esqueci a senha" e o reenvio do link): a
        //   mensagem do Symfony Mailer traz a resposta do servidor de e-mail, que costuma repetir o endereço
        //   de quem ia receber ("550 5.1.1 <maria@...>: Recipient address rejected"), e a do login no SMTP
        //   traz o usuário. O log fica com a classe do erro e o código (o da resposta do servidor, como 550,
        //   ou 0 quando ele nem respondeu).
        // Nos dois, mais o arquivo:linha do código do projeto que fez a consulta ou mandou o e-mail, sem a
        // mensagem e sem o rastro da pilha. Vale também para o erro embrulhado em outro, como o ViewException
        // de uma consulta feita dentro de uma view, que repete a mensagem dela. O return false impede o log
        // padrão do Laravel; os outros erros seguem para ele. O lembrete por e-mail não passa por aqui: o
        // ReminderSender trata a própria falha e grava só o id do agendamento.
        $exceptions->report(function (Throwable $e) {
            // O primeiro ponto da pilha fora do vendor é o código do projeto: o arquivo do próprio erro é
            // sempre o do Laravel (Connection.php) ou o do Symfony Mailer.
            $origin = function (Throwable $error): string {
                $vendor = base_path('vendor').DIRECTORY_SEPARATOR;
                $frame = collect($error->getTrace())
                    ->first(fn (array $frame) => isset($frame['file'], $frame['line']) && ! str_starts_with($frame['file'], $vendor));
                $location = $frame === null ? $error->getFile().':'.$error->getLine() : $frame['file'].':'.$frame['line'];

                return Str::after($location, base_path().DIRECTORY_SEPARATOR);
            };

            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof QueryException) {
                    Log::error('Erro no banco de dados.', [
                        'connection' => $cause->getConnectionName(),
                        'code' => $cause->getCode(),
                        'driver_code' => $cause->errorInfo[1] ?? null,
                        'file' => $origin($cause),
                    ]);

                    return false;
                }

                if ($cause instanceof TransportExceptionInterface) {
                    Log::error('Falha no envio de e-mail.', [
                        'class' => $cause::class,
                        'code' => $cause->getCode(),
                        'file' => $origin($cause),
                    ]);

                    return false;
                }
            }

            return null;
        });

        $wantsJson = fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

        // As mensagens abaixo valem para a API (e para quem pede JSON). As telas continuam
        // com o redirecionamento para o login e as páginas de erro.

        // 401: sem login, ou com token vencido ou revogado.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Não autenticado.'], 401);
        });

        // 404 da API em pt-BR, sem o nome do model (App\Models\X) que o Laravel põe na mensagem.
        // O Laravel transforma o ModelNotFoundException do route model binding em
        // NotFoundHttpException e guarda o original no getPrevious().
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $message = $e->getPrevious() instanceof ModelNotFoundException
                ? 'Registro não encontrado.'
                : 'Rota não encontrada.';

            return response()->json(['message' => $message], 404, $e->getHeaders());
        });

        // 405 mantém o cabeçalho Allow com os métodos aceitos pela rota.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Método não permitido para esta rota.'], 405, $e->getHeaders());
        });

        // 429 mantém Retry-After e X-RateLimit-*, que dizem ao cliente quando tentar de novo. O do
        // middleware throttle vem com a mensagem em inglês e ganha a de baixo; um 429 lançado pelo
        // próprio app com mensagem (como o limite do "Enviar lembrete agora", no ReminderSender) fica com ela.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            $message = $e instanceof ThrottleRequestsException || $e->getMessage() === ''
                ? "Muitas requisições. Tente de novo em {$seconds} segundos."
                : $e->getMessage();

            return response()->json(['message' => $message], 429, $e->getHeaders());
        });

        // 429 do login bloqueado depois de muitas senhas erradas (App\Services\Auth\LoginAttempt). O corpo
        // continua o de erro de validação, com a mensagem no campo e-mail, e ganha o Retry-After e os
        // X-RateLimit-* deste bloqueio. Nas telas, o Laravel volta ao formulário com o erro no campo.
        $exceptions->render(function (TooManyLoginAttemptsException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status, $e->headers());
        });

        // Os outros erros HTTP que a API usa. Fica depois dos anteriores, porque o Laravel usa o
        // primeiro callback que responde, e NotFound, MethodNotAllowed e TooManyRequests também
        // são HttpException.
        // - 403: policy ou Gate negou (a Policy vira AccessDeniedHttpException);
        // - 404: policy escondeu o registro (Response::denyAsNotFound(), como o serviço inativo para o cliente);
        // - 409: conflito com a agenda, com a mensagem de quem recusou (ex.: excluir um cadastro com
        //   agendamento futuro, em App\Models\Concerns\HasAppointments);
        // - 419: token CSRF da sessão vencido.
        $exceptions->render(function (HttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $message = match ($e->getStatusCode()) {
                403 => 'Você não tem permissão para esta ação.',
                404 => 'Registro não encontrado.',
                409 => $e->getMessage() !== '' ? $e->getMessage() : 'Conflito com o estado atual do registro.',
                419 => 'Sua sessão expirou. Recarregue a página.',
                default => null,
            };

            return $message === null
                ? null
                : response()->json(['message' => $message], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
