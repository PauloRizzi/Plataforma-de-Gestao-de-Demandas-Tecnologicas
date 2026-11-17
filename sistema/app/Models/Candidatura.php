<?php

namespace App\Models;

use App\Enums\StatusCandidatura;
use App\Observers\CandidaturaObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Candidatura do estudante a um sub-projeto (RF10), avaliada pela coordenação (RF11).
 */
#[Fillable(['subprojeto_id', 'estudante_id', 'status', 'avaliado_por', 'avaliado_em', 'motivo_recusa'])]
#[ObservedBy(CandidaturaObserver::class)]
class Candidatura extends Model
{
    protected $table = 'candidaturas';

    protected $attributes = [
        'status' => 'pendente',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusCandidatura::class,
            'avaliado_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subprojeto, $this>
     */
    public function subprojeto(): BelongsTo
    {
        return $this->belongsTo(Subprojeto::class);
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function estudante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'estudante_id');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function avaliador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'avaliado_por');
    }

    /**
     * @return HasOne<MembroEquipe, $this>
     */
    public function membro(): HasOne
    {
        return $this->hasOne(MembroEquipe::class);
    }

    /**
     * Candidatura aprovada de quem depois foi removido da equipe (E9 do teste completo).
     */
    public function foiRemovidoDaEquipe(): bool
    {
        return $this->status === StatusCandidatura::Aprovada
            && ! $this->subprojeto->ehMembroAtivo($this->estudante);
    }

    /**
     * Situação mostrada ao estudante e à coordenação: a aprovada de quem saiu da equipe aparece assim.
     */
    public function rotuloSituacao(): string
    {
        return $this->foiRemovidoDaEquipe() ? 'Aprovada · removido(a) da equipe' : $this->status->rotulo();
    }

    public function corSituacao(): string
    {
        return $this->foiRemovidoDaEquipe() ? 'secondary' : $this->status->cor();
    }

    public function estaPendente(): bool
    {
        return $this->status === StatusCandidatura::Pendente;
    }
}
