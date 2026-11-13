<?php

namespace App\Models;

use App\Models\Concerns\ReleasesEmailOnDelete;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Barber extends Model
{
    use HasFactory, ReleasesEmailOnDelete, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
    ];

    /**
     * Exclui o barbeiro sem apagar a linha (soft delete). Só o nome fica, para o
     * histórico de atendimentos mostrar quem atendeu. O e-mail é trocado por um
     * endereço que nunca recebe mensagem (.invalid), o que libera o original no
     * unique do banco para um cadastro novo, e o telefone, que não tem mais uso
     * depois da exclusão, é apagado (LGPD).
     */
    public function releaseEmailAndDelete(): void
    {
        DB::transaction(function () {
            $this->update([
                'email' => $this->releasedEmail(),
                'phone' => null,
            ]);

            $this->delete();
        });
    }
}
