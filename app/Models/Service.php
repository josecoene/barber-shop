<?php

namespace App\Models;

use App\Models\Concerns\HasAppointments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasAppointments, SoftDeletes;

    protected $fillable = ['name', 'duration', 'price', 'status'];

    /**
     * Padrão do banco repetido aqui para o JSON do POST já sair com o status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * price sai sempre como texto com 2 casas ("35.00"), igual em SQLite e MySQL.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
