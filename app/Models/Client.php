<?php

namespace App\Models;

use App\Models\Concerns\ReleasesEmailOnDelete;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, ReleasesEmailOnDelete, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];

    /**
     * Exclui o cliente sem apagar a linha (soft delete), para o histórico de
     * atendimentos continuar apontando para ela, e apaga os dados pessoais (LGPD).
     * O e-mail vira um endereço que nunca recebe mensagem (.invalid) e libera o
     * e-mail original para um cadastro novo.
     */
    public function anonymizeAndDelete(): void
    {
        DB::transaction(function () {
            $this->update([
                'name' => 'Cliente removido',
                'email' => $this->releasedEmail(),
                'phone' => null,
            ]);

            $this->delete();
        });
    }
}
