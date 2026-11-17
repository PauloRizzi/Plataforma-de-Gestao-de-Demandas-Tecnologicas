@extends('layouts.app')

@section('titulo', $ehEstudante ? 'Meus sub-projetos' : 'Sub-projetos')

@section('content')
<div class="container">
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
        <div>
            <h1 class="h3 mb-0">{{ $ehEstudante ? 'Meus sub-projetos' : 'Sub-projetos' }}</h1>
            <p class="text-muted small mb-0">
                @if ($ehEstudante)
                    Equipes de que você faz parte e as suas candidaturas.
                @else
                    Demandas aceitas e as suas equipes. Para criar um sub-projeto, abra uma demanda "Priorizada".
                @endif
            </p>
        </div>
        @if ($ehEstudante)
            <a href="{{ route('oportunidades.index') }}" class="btn btn-primary">Ver oportunidades</a>
        @endif
    </div>

    @if ($ehEstudante)
        {{-- Fase 9: projetos de extensão a que o estudante está vinculado pela coordenação. --}}
        <h2 class="h5">Meus projetos de extensão</h2>
        <div class="card shadow-sm mb-4">
            <ul class="list-group list-group-flush">
                @forelse ($meusProjetos as $projeto)
                    <li class="list-group-item d-flex align-items-center gap-2">
                        <img src="{{ $projeto->urlImagem() }}" alt="" class="miniatura-projeto rounded" loading="lazy">
                        <div>
                            @if ($projeto->ativo)
                                <a href="{{ route('projetos-base.show', $projeto) }}" class="text-body">{{ $projeto->titulo }}</a>
                            @else
                                {{ $projeto->titulo }} <span class="badge text-bg-secondary">Inativo</span>
                            @endif
                            <div class="small text-muted">{{ $projeto->periodo() }}</div>
                        </div>
                    </li>
                @empty
                    <li class="list-group-item text-muted small">Você ainda não está vinculado a nenhum projeto de extensão. O vínculo é feito pela coordenação do projeto.</li>
                @endforelse
            </ul>
        </div>

        <h2 class="h5">Minhas equipes</h2>
    @endif

    {{-- Fase 9: busca e filtros da lista. --}}
    <form method="GET" action="{{ route('subprojetos.index') }}" class="card card-body shadow-sm mb-3" role="search">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="busca" class="form-label small">Buscar</label>
                <input id="busca" type="search" name="busca" class="form-control" value="{{ $filtros['busca'] ?? '' }}" placeholder="Título ou escopo" maxlength="100">
            </div>
            <div class="col-md-3">
                <label for="projeto" class="form-label small">Projeto de extensão</label>
                <select id="projeto" name="projeto" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($projetos as $projeto)
                        <option value="{{ $projeto->id }}" @selected((string) ($filtros['projeto'] ?? '') === (string) $projeto->id)>{{ $projeto->titulo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="status" class="form-label small">Status da demanda</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($statusOpcoes as $status)
                        <option value="{{ $status->value }}" @selected(($filtros['status'] ?? '') === $status->value)>{{ $status->rotulo() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="modalidade" class="form-label small">Modalidade</label>
                <select id="modalidade" name="modalidade" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($modalidades as $modalidade)
                        <option value="{{ $modalidade->value }}" @selected(($filtros['modalidade'] ?? '') === $modalidade->value)>{{ $modalidade->rotulo() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Filtrar</button>
                <a href="{{ route('subprojetos.index') }}" class="btn btn-outline-secondary" title="Limpar filtros">Limpar</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Sub-projeto</th>
                        <th class="d-none d-md-table-cell">Projeto de extensão</th>
                        <th>Demanda</th>
                        <th class="d-none d-sm-table-cell">Equipe</th>
                        @unless ($ehEstudante)
                            <th>Candidaturas</th>
                        @endunless
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subprojetos as $subprojeto)
                        <tr>
                            <td>
                                <a href="{{ route('subprojetos.show', $subprojeto) }}" class="text-body">{{ $subprojeto->titulo }}</a>
                                <div class="small text-muted">{{ $subprojeto->modalidade->rotulo() }} · {{ $subprojeto->periodo() }}</div>
                                @if (! $ehEstudante && $subprojeto->entregas_para_validar_count)
                                    <span class="badge text-bg-warning">{{ $subprojeto->entregas_para_validar_count }} entrega(s) para validar</span>
                                @endif
                            </td>
                            <td class="d-none d-md-table-cell small">{{ $subprojeto->solicitacao->projetoBase->titulo }}</td>
                            <td>
                                <span class="badge text-bg-{{ $subprojeto->solicitacao->status->cor() }}">{{ $subprojeto->solicitacao->status->rotulo() }}</span>
                            </td>
                            <td class="d-none d-sm-table-cell small text-nowrap">{{ $subprojeto->membros_ativos_count }} de {{ $subprojeto->vagas }}</td>
                            @unless ($ehEstudante)
                                <td>
                                    @if ($subprojeto->candidaturas_pendentes_count)
                                        <a href="{{ route('subprojetos.show', $subprojeto) }}" class="badge text-bg-warning text-decoration-none">{{ $subprojeto->candidaturas_pendentes_count }} pendente(s)</a>
                                    @else
                                        <span class="small text-muted">—</span>
                                    @endif
                                </td>
                            @endunless
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                @if ($filtrando)
                                    Nenhum sub-projeto encontrado com esses filtros.
                                @else
                                    {{ $ehEstudante ? 'Você ainda não faz parte de nenhuma equipe.' : 'Nenhum sub-projeto criado.' }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-4">
        {{ $subprojetos->links() }}
    </div>

    @if ($ehEstudante)
        <h2 class="h5">Minhas candidaturas</h2>
        <div class="card shadow-sm">
            <ul class="list-group list-group-flush">
                @forelse ($minhasCandidaturas as $candidatura)
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <a href="{{ route('oportunidades.show', $candidatura->subprojeto) }}" class="text-body">{{ $candidatura->subprojeto->titulo }}</a>
                            <div class="small text-muted">{{ $candidatura->subprojeto->solicitacao->projetoBase->titulo }} · enviada em {{ $candidatura->created_at->format('d/m/Y') }}</div>
                            @if ($candidatura->motivo_recusa)
                                <div class="small">Motivo da recusa: {{ $candidatura->motivo_recusa }}</div>
                            @endif
                        </div>
                        <span class="badge text-bg-{{ $candidatura->corSituacao() }}">{{ $candidatura->rotuloSituacao() }}</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">Nenhuma candidatura enviada. Veja as vagas em "Oportunidades".</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
@endsection
