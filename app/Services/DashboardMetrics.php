<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Barber;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Números do dashboard do admin (GET /api/dashboard) num período de datas, com
 * o primeiro e o último dia incluídos. Tudo conta pela data do atendimento
 * (appointments.date), inclusive o faturamento: um pagamento registrado dias
 * depois entra no dia do atendimento.
 *
 * As consultas são agregadas no banco (count e sum com group by): nenhuma
 * carrega os agendamentos ou os pagamentos um a um, e o número de consultas
 * não muda com o volume. Rodam igual no SQLite e no MySQL: o appointments.date
 * já é o texto Y-m-d, os horários são H:i:s, e nenhuma consulta usa função de
 * data, que muda de um banco para o outro.
 *
 * Dinheiro sai como texto com 2 casas ("35.00"), igual ao price. Taxas saem em
 * %, com 1 casa decimal, e ficam null quando o divisor é zero.
 */
class DashboardMetrics
{
    public function __construct(private readonly AppointmentScheduler $scheduler) {}

    /**
     * Todos os números do período ($from e $to em Y-m-d).
     *
     * @return array<string, mixed>
     */
    public function forPeriod(string $from, string $to): array
    {
        $statuses = $this->appointmentsByStatus($from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to, 'days' => count($this->days($from, $to))],
            'revenue' => $this->revenue($from, $to),
            'appointments' => [
                'total' => array_sum(array_column($statuses, 'count')),
                'by_status' => $statuses,
                'no_show_rate' => $this->noShowRate($statuses),
            ],
            'occupancy' => $this->occupancy($from, $to),
            'top_services' => $this->topServices($from, $to),
            'barbers' => $this->barbers($from, $to),
        ];
    }

    /**
     * Faturamento: soma dos pagamentos com status pago (paid) dos atendimentos
     * do período. Pendentes e estornados ficam de fora, e o pagamento de valor
     * zero também (o corte grátis da fidelidade, com 100% de desconto, ou uma
     * cortesia): não entrou dinheiro, então ele não conta como pagamento, não
     * baixa o ticket médio nem aparece como recebido na forma escolhida. O
     * atendimento dele continua contando como concluído (por status, nos top
     * serviços e nos barbeiros). O prêmio com desconto parcial tem valor e
     * conta como qualquer pagamento. Sai:
     * - total e payments: a soma e a quantidade de pagamentos pagos com valor;
     * - average_ticket (ticket médio): total ÷ payments, 0 quando não há pagamento;
     * - by_method: as quatro formas de pagamento, mesmo sem valor;
     * - by_day: todos os dias do período, mesmo sem valor.
     *
     * @return array{total: string, payments: int, average_ticket: string, by_method: list<array{method: string, label: string, payments: int, total: string}>, by_day: list<array{date: string, payments: int, total: string}>}
     */
    private function revenue(string $from, string $to): array
    {
        $byMethod = $this->paidPayments($from, $to)
            ->groupBy('payments.method')
            ->get(['payments.method', DB::raw('count(*) as quantity'), DB::raw('sum(payments.amount) as total')])
            ->keyBy('method');

        $byDay = $this->paidPayments($from, $to)
            ->groupBy('appointments.date')
            ->get(['appointments.date', DB::raw('count(*) as quantity'), DB::raw('sum(payments.amount) as total')])
            ->keyBy('date');

        $total = $byMethod->sum(fn (object $row): float => (float) $row->total);
        $payments = $byMethod->sum(fn (object $row): int => (int) $row->quantity);

        return [
            'total' => $this->money($total),
            'payments' => $payments,
            'average_ticket' => $this->money($payments > 0 ? $total / $payments : 0),
            'by_method' => array_map(fn (PaymentMethod $method): array => [
                'method' => $method->value,
                'label' => $method->label(),
                'payments' => (int) ($byMethod->get($method->value)?->quantity ?? 0),
                'total' => $this->money($byMethod->get($method->value)?->total ?? 0),
            ], PaymentMethod::cases()),
            'by_day' => array_map(fn (string $date): array => [
                'date' => $date,
                'payments' => (int) ($byDay->get($date)?->quantity ?? 0),
                'total' => $this->money($byDay->get($date)?->total ?? 0),
            ], $this->days($from, $to)),
        ];
    }

    /**
     * Atendimentos por status: quantos agendamentos do período estão em cada
     * status, com o nome em português. Sai um item por status, mesmo sem
     * agendamento, na ordem do AppointmentStatus.
     *
     * @return list<array{status: string, label: string, count: int}>
     */
    private function appointmentsByStatus(string $from, string $to): array
    {
        $counts = DB::table('appointments')
            ->whereBetween('date', [$from, $to])
            ->groupBy('status')
            ->get(['status', DB::raw('count(*) as quantity')])
            ->pluck('quantity', 'status');

        return array_map(fn (AppointmentStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($counts[$status->value] ?? 0),
        ], AppointmentStatus::cases());
    }

    /**
     * Taxa de faltas: faltou ÷ (concluído + faltou), em %. Cancelado não conta
     * como falta, nem quando a barbearia cancela em cima da hora. null quando
     * não há atendimento concluído nem falta no período.
     *
     * @param  list<array{status: string, label: string, count: int}>  $statuses
     */
    private function noShowRate(array $statuses): ?float
    {
        $counts = array_column($statuses, 'count', 'status');
        $noShow = $counts[AppointmentStatus::NoShow->value];
        $base = $counts[AppointmentStatus::Completed->value] + $noShow;

        return $base > 0 ? round($noShow / $base * 100, 1) : null;
    }

    /**
     * Ocupação: minutos ocupados ÷ minutos disponíveis, em %.
     * - occupied_minutes: a soma da duração (início ao término) dos agendamentos
     *   do período com status que ocupa o horário (agendado, confirmado, em
     *   atendimento e concluído; cancelado e faltou liberam o horário);
     * - available_minutes: minutes_per_day (fechamento menos abertura, da config
     *   BUSINESS_*) × business_days (os dias de expediente dentro do período,
     *   inclusive os que ainda não chegaram) × barbers;
     * - barbers: os barbeiros cadastrados até o último dia do período e não
     *   excluídos antes do primeiro (ver barbersOfPeriod()).
     *
     * rate fica null quando não há minuto disponível (nenhum barbeiro ou nenhum
     * dia de expediente no período).
     *
     * @return array{rate: float|null, occupied_minutes: int, available_minutes: int, minutes_per_day: int, business_days: int, barbers: int}
     */
    private function occupancy(string $from, string $to): array
    {
        // Um grupo por par de horários (início, término), não por agendamento: são poucos pares, e a
        // duração sai da conta em PHP, sem função de horário do banco.
        $occupied = DB::table('appointments')
            ->whereBetween('date', [$from, $to])
            ->whereIn('status', AppointmentStatus::occupying())
            ->groupBy('start_time', 'end_time')
            ->get(['start_time', 'end_time', DB::raw('count(*) as quantity')])
            ->sum(fn (object $row): int => ($this->minutes($row->end_time) - $this->minutes($row->start_time)) * (int) $row->quantity);

        $hours = $this->scheduler->businessHours();
        $minutesPerDay = max(0, $hours['closing'] - $hours['opening']);
        $businessDays = count(array_filter(
            $this->days($from, $to),
            fn (string $date): bool => in_array($this->date($date)->dayOfWeekIso, $hours['days'], true),
        ));
        $barbers = $this->barbersOfPeriod($from, $to)->count();
        $available = $minutesPerDay * $businessDays * $barbers;

        return [
            'rate' => $available > 0 ? round($occupied / $available * 100, 1) : null,
            'occupied_minutes' => $occupied,
            'available_minutes' => $available,
            'minutes_per_day' => $minutesPerDay,
            'business_days' => $businessDays,
            'barbers' => $barbers,
        ];
    }

    /**
     * Top 5 serviços: os que mais tiveram atendimentos concluídos no período,
     * com o faturamento deles (pagamentos pagos desses atendimentos). Empate na
     * quantidade fica com o maior faturamento e, depois, com o menor id. Serviço
     * excluído continua aparecendo com o nome dele.
     *
     * @return list<array{id: int, name: string, completed: int, revenue: string}>
     */
    private function topServices(string $from, string $to): array
    {
        $rows = DB::table('appointments')
            ->leftJoin('payments', fn (JoinClause $join) => $this->joinPaidPayment($join))
            ->where('appointments.status', AppointmentStatus::Completed)
            ->whereBetween('appointments.date', [$from, $to])
            ->groupBy('appointments.service_id')
            ->orderByDesc('completed')
            ->orderByDesc('revenue')
            ->orderBy('appointments.service_id')
            ->limit(5)
            ->get([
                'appointments.service_id',
                DB::raw('count(*) as completed'),
                DB::raw('coalesce(sum(payments.amount), 0) as revenue'),
            ]);

        $names = Service::withTrashed()->whereIn('id', $rows->pluck('service_id'))->pluck('name', 'id');

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->service_id,
            'name' => (string) $names->get($row->service_id),
            'completed' => (int) $row->completed,
            'revenue' => $this->money($row->revenue),
        ])->all();
    }

    /**
     * Resumo por barbeiro: atendimentos concluídos, faltas e faturamento
     * (pagamentos pagos dos atendimentos dele) no período. Entram os barbeiros
     * que contam na ocupação, mesmo sem atendimento, e os que tiveram
     * agendamento no período. Ordem: maior faturamento, mais concluídos, nome e
     * id. Barbeiro excluído continua aparecendo com o nome dele.
     *
     * @return list<array{id: int, name: string, completed: int, no_show: int, revenue: string}>
     */
    private function barbers(string $from, string $to): array
    {
        $totals = DB::table('appointments')
            ->leftJoin('payments', fn (JoinClause $join) => $this->joinPaidPayment($join))
            ->whereBetween('appointments.date', [$from, $to])
            ->groupBy('appointments.barber_id')
            ->select('appointments.barber_id')
            ->selectRaw('sum(case when appointments.status = ? then 1 else 0 end) as completed', [AppointmentStatus::Completed->value])
            ->selectRaw('sum(case when appointments.status = ? then 1 else 0 end) as no_show', [AppointmentStatus::NoShow->value])
            ->selectRaw('coalesce(sum(payments.amount), 0) as revenue')
            ->get()
            ->keyBy('barber_id');

        return $this->barbersOfPeriod($from, $to)
            ->orWhereIn('id', $totals->keys()->all())
            ->get(['id', 'name'])
            ->map(fn (Barber $barber): array => [
                'id' => $barber->id,
                'name' => $barber->name,
                'completed' => (int) ($totals->get($barber->id)?->completed ?? 0),
                'no_show' => (int) ($totals->get($barber->id)?->no_show ?? 0),
                'revenue' => (float) ($totals->get($barber->id)?->revenue ?? 0),
            ])
            ->sortBy([['revenue', 'desc'], ['completed', 'desc'], ['name', 'asc'], ['id', 'asc']])
            ->map(fn (array $barber): array => [...$barber, 'revenue' => $this->money($barber['revenue'])])
            ->values()
            ->all();
    }

    /**
     * Barbeiros que contam no período: os cadastrados até o último dia dele e
     * não excluídos antes do primeiro (o excluído no meio do período trabalhou
     * em parte dele). Quem foi cadastrado depois do período não conta: ninguém
     * agenda no passado, então ele não pode ter atendimento ali, e assim os
     * números de um período que já passou não mudam com um cadastro novo.
     * Cadastro sem created_at (gravado sem os timestamps) conta.
     *
     * Não há data de contratação: o barbeiro cadastrado no meio do período
     * conta o período inteiro. As datas são comparadas como texto no fuso do
     * app, o mesmo em que o Laravel grava created_at e deleted_at.
     *
     * @return EloquentBuilder<Barber>
     */
    private function barbersOfPeriod(string $from, string $to): EloquentBuilder
    {
        return Barber::withTrashed()->where(fn (EloquentBuilder $query) => $query
            ->where(fn (EloquentBuilder $created) => $created
                ->whereNull('created_at')
                ->orWhere('created_at', '<=', "{$to} 23:59:59"))
            ->where(fn (EloquentBuilder $deleted) => $deleted
                ->whereNull('deleted_at')
                ->orWhere('deleted_at', '>=', "{$from} 00:00:00")));
    }

    /**
     * Pagamentos pagos e com valor dos atendimentos do período (o de valor
     * zero não entrou dinheiro: ver revenue()).
     */
    private function paidPayments(string $from, string $to): Builder
    {
        return DB::table('payments')
            ->join('appointments', 'appointments.id', '=', 'payments.appointment_id')
            ->where('payments.status', PaymentStatus::Paid)
            ->where('payments.amount', '>', 0)
            ->whereBetween('appointments.date', [$from, $to]);
    }

    /**
     * Liga o agendamento ao pagamento dele só quando o pagamento está pago: o
     * faturamento dos serviços e dos barbeiros soma só esses.
     */
    private function joinPaidPayment(JoinClause $join): JoinClause
    {
        return $join->on('payments.appointment_id', '=', 'appointments.id')
            ->where('payments.status', PaymentStatus::Paid);
    }

    /**
     * Os dias do período, em Y-m-d, do primeiro ao último.
     *
     * @return list<string>
     */
    private function days(string $from, string $to): array
    {
        $days = [];
        $last = $this->date($to);

        // Compara datas, não texto: o dia seguinte a 9999-12-31 é "10000-01-01", que como texto vem antes.
        for ($day = $this->date($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * Data em UTC só para contar dias e achar o dia da semana: sem horário de
     * verão no meio da conta.
     */
    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }

    /**
     * "09:30:00" em minutos desde a meia-noite (570).
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 60 + $minutes;
    }

    /**
     * Valor em texto com 2 casas ("35.00"), igual ao price.
     */
    private function money(float|int|string $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
