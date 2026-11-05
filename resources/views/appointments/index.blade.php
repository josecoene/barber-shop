@extends('layouts.app')

@section('title', 'Agenda')

@section('content')
    <div data-schedule>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0">Agenda</h1>
            <div class="d-flex gap-2 align-items-center">
                <label for="schedule-date" class="visually-hidden">Data</label>
                <input type="date" class="form-control" id="schedule-date" data-date>
                <a class="btn btn-primary text-nowrap" href="{{ route('appointments.create') }}">Novo agendamento</a>
            </div>
        </div>

        <div class="alert alert-info" role="status">
            A agenda ainda é só visual: a API não tem agendamentos, então nenhum horário aparece marcado.
        </div>

        <div data-alerts></div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle text-center mb-0">
                    <thead class="table-light" data-schedule-head>
                        <tr>
                            <th scope="col">Horário</th>
                        </tr>
                    </thead>
                    <tbody data-schedule-body>
                        <tr>
                            <td class="text-body-secondary py-4">Carregando...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
