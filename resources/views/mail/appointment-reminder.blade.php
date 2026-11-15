{{--
    Lembrete por e-mail do horário (App\Mail\AppointmentReminder), em Markdown. As linhas ficam
    sem recuo: no Markdown, uma linha recuada vira bloco de código.
--}}
<x-mail::message>
# Lembrete do seu horário

Olá! Seu horário na {{ $barbershop['name'] }} está chegando.

<x-mail::panel>
- **Data:** {{ $date }}
- **Horário:** {{ $time }}
- **Serviço:** {{ $service }}
- **Barbeiro:** {{ $barber }}
</x-mail::panel>

@if ($barbershop['address'] !== '')
**Endereço:** {{ $barbershop['address'] }}

@endif
@if ($barbershop['phone'] !== '')
**Telefone:** {{ $barbershop['phone'] }}

@endif
## Precisa cancelar?

@if ($myAppointmentsUrl !== null)
Você pode cancelar pela sua conta, em Meus horários, {{ $cancelUntil }}.

<x-mail::button :url="$myAppointmentsUrl">
Abrir Meus horários
</x-mail::button>
@else
Fale com a barbearia {{ $cancelUntil }}.
@endif

Até logo!

Equipe {{ $barbershop['name'] }}
</x-mail::message>
