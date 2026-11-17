<?php

namespace App\Models;

use App\Enums\Perfil;
use App\Enums\StatusUsuario;
use App\Models\Concerns\TemImagem;
use Database\Factories\UsuarioFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notifiable;

#[Fillable(['nome', 'email', 'password', 'perfil', 'status', 'motivo_status', 'telefone', 'aprovado_por', 'aprovado_em', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class Usuario extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UsuarioFactory> */
    use HasFactory, Notifiable, TemImagem;

    protected $table = 'usuarios';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'aprovado_em' => 'datetime',
            'password' => 'hashed',
            'perfil' => Perfil::class,
            'status' => StatusUsuario::class,
        ];
    }

    /**
     * @return HasOne<Estudante, $this>
     */
    public function estudante(): HasOne
    {
        return $this->hasOne(Estudante::class, 'usuario_id');
    }

    /**
     * @return HasOne<Demandante, $this>
     */
    public function demandante(): HasOne
    {
        return $this->hasOne(Demandante::class, 'usuario_id');
    }

    /**
     * @return HasOne<Coordenador, $this>
     */
    public function coordenador(): HasOne
    {
        return $this->hasOne(Coordenador::class, 'usuario_id');
    }

    /**
     * Equipes de sub-projetos de que o estudante participa ou participou (RF11 e RF15).
     *
     * @return HasMany<MembroEquipe, $this>
     */
    public function participacoes(): HasMany
    {
        return $this->hasMany(MembroEquipe::class, 'estudante_id');
    }

    /**
     * Fase 9: Projetos Base a que o estudante extensionista está vinculado.
     *
     * @return BelongsToMany<ProjetoBase, $this>
     */
    public function projetosVinculados(): BelongsToMany
    {
        return $this->belongsToMany(ProjetoBase::class, 'estudantes_projeto_base', 'estudante_id', 'projeto_base_id')
            ->withTimestamps()
            ->orderBy('titulo');
    }

    /**
     * @return BelongsTo<Usuario, $this>
     */
    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprovado_por');
    }

    /**
     * Filtros do painel do admin (RF04): nome, e-mail, perfil e status.
     *
     * @param  Builder<Usuario>  $query
     * @param  array{busca?: ?string, perfil?: ?string, status?: ?string}  $filtros
     */
    public function scopeFiltrar(Builder $query, array $filtros): void
    {
        $query
            ->when($filtros['busca'] ?? null, function (Builder $query, string $busca): void {
                $query->where(function (Builder $query) use ($busca): void {
                    $query->where('nome', 'like', "%{$busca}%")->orWhere('email', 'like', "%{$busca}%");
                });
            })
            ->when($filtros['perfil'] ?? null, fn (Builder $query, string $perfil) => $query->where('perfil', $perfil))
            ->when($filtros['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
    }

    public function temPerfil(Perfil ...$perfis): bool
    {
        return in_array($this->perfil, $perfis, true);
    }

    public function estaAtivo(): bool
    {
        return $this->status === StatusUsuario::Ativo;
    }

    /**
     * Mensagem mostrada a quem tenta entrar com a conta inativa ou rejeitada.
     */
    public function mensagemBloqueio(): ?string
    {
        $motivo = $this->motivo_status ? " Motivo: {$this->motivo_status}" : '';

        return match ($this->status) {
            StatusUsuario::Rejeitado => "Seu cadastro não foi aprovado.{$motivo} Em caso de dúvida, procure a coordenação do projeto.",
            StatusUsuario::Inativo => "Sua conta está inativa.{$motivo} Em caso de dúvida, procure a coordenação do projeto.",
            default => null,
        };
    }

    /**
     * Fase 9: foto de perfil. Sem foto enviada, usa o avatar padrão do perfil,
     * que acompanha a troca de perfil feita pelo admin.
     */
    public function urlFoto(): string
    {
        return $this->foto
            ? route('usuarios.foto', [$this, 'v' => substr(md5($this->foto), 0, 8)])
            : asset("images/avatares/{$this->perfil->value}.svg");
    }

    public function trocarFoto(UploadedFile $arquivo): void
    {
        $this->gravarImagem($arquivo, 'foto', "fotos/{$this->id}", 400, true);
    }

    public function removerFoto(): void
    {
        $this->apagarImagem('foto');
    }

    /**
     * Nome usado pelas notificações padrão do Laravel.
     */
    public function getNameAttribute(): string
    {
        return $this->nome;
    }
}
