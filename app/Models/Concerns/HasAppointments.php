<?php

namespace App\Models\Concerns;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cadastros que aparecem na agenda: cliente, barbeiro e serviço.
 */
trait HasAppointments
{
    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Recusa a exclusão (409) de quem tem agendamento de hoje em diante ainda
     * ativo (agendado, confirmado ou em atendimento). Com só agendamentos
     * passados, cancelados ou com falta, a exclusão segue normalmente. Chame no
     * começo da transação da exclusão.
     *
     * A linha do cadastro é travada antes da consulta à agenda. O
     * AppointmentScheduler trava o mesmo cadastro antes de gravar um
     * agendamento, então um agendamento feito ao mesmo tempo que a exclusão ou
     * termina antes dela (e aparece aqui) ou espera a exclusão terminar (e é
     * recusado, porque o cadastro foi excluído).
     *
     * @throws ConflictHttpException
     */
    public function ensureNoUpcomingAppointments(): void
    {
        static::query()->whereKey($this->getKey())->lockForUpdate()->first();

        if ($this->appointments()->upcoming()->lockForUpdate()->exists()) {
            throw new ConflictHttpException('Este cadastro tem agendamentos futuros. Cancele ou remarque antes de excluir.');
        }
    }
}
