<?php

namespace App\Models;

use App\Observers\MembroEquipeObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estudante na equipe do sub-projeto (RF11). A remoção preenche `removido_em` e não apaga o registro.
 */
#[Fillable(['subprojeto_id', 'estudante_id', 'papel_id', 'candidatura_id', 'removido_em'])]
#[ObservedBy(MembroEquipeObserver::class)]
class MembroEquipe extends Model
{
    protected $table = 'membros_equipe';

    protected function casts(): array
    {
        return ['removido_em' => 'datetime'];
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
     * @return BelongsTo<PapelEquipe, $this>
     */
    public function papel(): BelongsTo
    {
        return $this->belongsTo(PapelEquipe::class, 'papel_id');
    }

    /**
     * @return BelongsTo<Candidatura, $this>
     */
    public function candidatura(): BelongsTo
    {
        return $this->belongsTo(Candidatura::class);
    }

    /**
     * Membros que continuam na equipe.
     *
     * @param  Builder<MembroEquipe>  $query
     */
    public function scopeAtivos(Builder $query): void
    {
        $query->whereNull('removido_em');
    }

    public function foiRemovido(): bool
    {
        return $this->removido_em !== null;
    }

    /**
     * Coloca o estudante na equipe. Quem já foi removido volta no mesmo registro (a tabela aceita
     * um registro por estudante e sub-projeto), com o papel novo e sem a data de remoção.
     */
    public static function colocarNaEquipe(Subprojeto $subprojeto, int $estudanteId, int $papelId, ?int $candidaturaId = null): self
    {
        $membro = static::query()->firstOrNew(['subprojeto_id' => $subprojeto->id, 'estudante_id' => $estudanteId]);

        $membro->fill([
            'papel_id' => $papelId,
            'candidatura_id' => $candidaturaId ?? $membro->candidatura_id,
            'removido_em' => null,
        ])->save();

        return $membro;
    }
}
