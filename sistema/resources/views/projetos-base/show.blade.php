@extends('layouts.app')

@section('titulo', $projeto->titulo)

@section('content')
<div class="container">
    <div class="mb-2">
        @include('layouts._voltar', ['url' => route('projetos-base.index'), 'destino' => 'Projetos Base'])
    </div>

    <div class="capa-projeto rounded shadow-sm mb-3">
        <img src="{{ $projeto->urlImagem() }}" alt="{{ $projeto->imagem ? "Imagem do projeto {$projeto->titulo}" : '' }}">
    </div>

    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">{{ $projeto->titulo }}</h1>
            <span class="badge text-bg-light border">{{ $projeto->tipologia->nome }}</span>
            @can('create', \App\Models\ProjetoBase::class)
                <span class="badge text-bg-{{ $projeto->ativo ? 'success' : 'secondary' }}">{{ $projeto->ativo ? 'Ativo' : 'Inativo' }}</span>
                @unless ($projeto->publico)
                    <span class="badge text-bg-warning">Oculto da página pública</span>
                @endunless
            @endcan
        </div>
        <div class="d-flex flex-wrap gap-2">
            {{-- Fase 9: coordenador (no projeto que coordena) e admin também abrem demandas. --}}
            @if (! auth()->user()->temPerfil(\App\Enums\Perfil::Demandante))
                @can('criarNoProjeto', [\App\Models\Solicitacao::class, $projeto])
                    <a href="{{ route('demandas.create', ['projeto' => $projeto->id]) }}" class="btn btn-primary">Nova demanda neste projeto</a>
                @endcan
            @endif
            @can('update', $projeto)
                <a href="{{ route('projetos-base.edit', $projeto) }}" class="btn btn-outline-primary">Editar</a>
            @endcan
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Descrição</h2>
                    <p style="white-space: pre-line">{{ $projeto->descricao }}</p>

                    <h2 class="h6 text-muted">Escopo preliminar</h2>
                    @if ($projeto->escopo_preliminar)
                        <p class="mb-0" style="white-space: pre-line">{{ $projeto->escopo_preliminar }}</p>
                    @else
                        <p class="text-muted mb-0">Não informado.</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <dl class="mb-0">
                        <dt class="small text-muted">Coordenador responsável</dt>
                        <dd>
                            @if ($projeto->coordenador)
                                {{ $projeto->coordenador->nome }}
                                @if ($projeto->coordenador->coordenador?->departamento)
                                    <div class="small text-muted">{{ $projeto->coordenador->coordenador->departamento }}</div>
                                @endif
                            @else
                                <span class="text-warning-emphasis">A definir pelo administrador</span>
                            @endif
                        </dd>

                        <dt class="small text-muted">Período</dt>
                        <dd>{{ $projeto->periodo() }}</dd>

                        <dt class="small text-muted">Tipologia</dt>
                        <dd class="mb-0">{{ $projeto->tipologia->nome }}</dd>
                    </dl>
                    @if ($vinculado)
                        <div class="alert alert-success small mt-3 mb-0">Você é estudante extensionista deste projeto.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Fase 9: estudantes vinculados, só para o admin e o coordenador do projeto. --}}
    @if ($estudantes !== null)
        <div class="card shadow-sm mt-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                    <h2 class="h6 text-muted mb-0">Estudantes extensionistas vinculados ({{ $estudantes->count() }})</h2>
                    <a href="{{ route('projetos-base.edit', $projeto) }}" class="btn btn-sm btn-outline-secondary">Alterar</a>
                </div>
                @forelse ($estudantes as $estudante)
                    <div class="d-flex align-items-center gap-2 border-top py-2">
                        <img src="{{ $estudante->urlFoto() }}" alt="" class="avatar avatar-sm" loading="lazy">
                        <div>
                            <div class="small fw-semibold">
                                {{ $estudante->nome }}
                                @unless ($estudante->estaAtivo())
                                    <span class="badge text-bg-secondary">Conta inativa</span>
                                @endunless
                            </div>
                            <div class="small text-muted">{{ $estudante->estudante?->curso?->nome }}{{ $estudante->estudante?->matricula ? " · Matrícula {$estudante->estudante->matricula}" : '' }}</div>
                        </div>
                    </div>
                @empty
                    <p class="small text-muted mb-0">Nenhum estudante vinculado. Sem vínculo, a coordenação não consegue colocar estudantes nas equipes dos sub-projetos deste projeto.</p>
                @endforelse
            </div>
        </div>
    @endif
</div>
@endsection
