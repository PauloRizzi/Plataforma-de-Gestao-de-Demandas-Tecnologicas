@extends('layouts.app')

@section('titulo', $usuario->nome)

@php
    use App\Enums\Perfil;
    use App\Enums\StatusUsuario;

    $pendente = $usuario->status === StatusUsuario::Pendente;
    $escola = $usuario->demandante?->escolaInstituicao;
@endphp

@section('content')
<div class="container">
    @include('layouts._voltar', ['url' => route('admin.usuarios.index'), 'destino' => 'usuários'])

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mt-2 mb-3">
        <div class="d-flex align-items-center gap-3">
            <img src="{{ $usuario->urlFoto() }}" alt="" class="avatar border" style="width: 4rem; height: 4rem">
            <div>
                <h1 class="h3 mb-0">{{ $usuario->nome }}</h1>
                <div class="text-muted">{{ $usuario->perfil->rotulo() }}</div>
            </div>
        </div>
        <span class="badge fs-6 text-bg-{{ $usuario->status->cor() }}">{{ $usuario->status->rotulo() }}</span>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $erro)
                    <li>{{ $erro }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($proprioUsuario)
        <div class="alert alert-info">Esta é a sua conta. Você não pode alterar o seu próprio status ou perfil.</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Dados do cadastro</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">E-mail</dt>
                        <dd class="col-sm-8">
                            {{ $usuario->email }}
                            @if ($usuario->hasVerifiedEmail())
                                <span class="badge text-bg-light border">confirmado</span>
                            @else
                                <span class="badge text-bg-warning">não confirmado</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Telefone</dt>
                        <dd class="col-sm-8">{{ $usuario->telefone ?: '—' }}</dd>

                        @if ($usuario->perfil === Perfil::Estudante && $usuario->estudante)
                            <dt class="col-sm-4">Matrícula</dt>
                            <dd class="col-sm-8">{{ $usuario->estudante->matricula }}</dd>
                            <dt class="col-sm-4">Curso</dt>
                            <dd class="col-sm-8">{{ $usuario->estudante->curso->nome }}</dd>
                        @endif

                        @if ($usuario->perfil === Perfil::Demandante && $usuario->demandante)
                            <dt class="col-sm-4">Cargo</dt>
                            <dd class="col-sm-8">{{ $usuario->demandante->cargo }}</dd>
                            <dt class="col-sm-4">Comunidade/instituição</dt>
                            <dd class="col-sm-8">
                                {{ $escola->nomeCompleto() }}
                                @unless ($escola->ativo)
                                    <span class="badge text-bg-warning">nova, a conferir</span>
                                @endunless
                            </dd>
                        @endif

                        @if ($usuario->perfil === Perfil::Coordenador && $usuario->coordenador)
                            <dt class="col-sm-4">SIAPE</dt>
                            <dd class="col-sm-8">{{ $usuario->coordenador->siape ?: '—' }}</dd>
                            <dt class="col-sm-4">Departamento</dt>
                            <dd class="col-sm-8">{{ $usuario->coordenador->departamento ?: '—' }}</dd>
                        @endif

                        <dt class="col-sm-4">Cadastrado em</dt>
                        <dd class="col-sm-8">{{ $usuario->created_at->format('d/m/Y H:i') }}</dd>

                        @if ($usuario->aprovado_em)
                            <dt class="col-sm-4">Aprovado em</dt>
                            <dd class="col-sm-8">
                                {{ $usuario->aprovado_em->format('d/m/Y H:i') }}
                                @if ($usuario->aprovador) por {{ $usuario->aprovador->nome }} @endif
                            </dd>
                        @endif

                        @if ($usuario->motivo_status)
                            <dt class="col-sm-4">Motivo do status</dt>
                            <dd class="col-sm-8">{{ $usuario->motivo_status }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- E10 do teste completo: corrigir os dados sem trocar o perfil. --}}
            @php($errosDados = $errors->getBag('dados'))
            <div class="card shadow-sm mb-3" id="editar-dados">
                <div class="card-header bg-white fw-semibold">Editar dados</div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Corrija aqui nome, telefone e os dados do perfil atual{{ match ($usuario->perfil) {
                        Perfil::Estudante => ' (matrícula e curso)',
                        Perfil::Demandante => ' (comunidade/instituição e cargo)',
                        Perfil::Coordenador => ' (SIAPE e departamento)',
                        default => '',
                    } }}, sem trocar o perfil.</p>
                    @if ($errosDados->any())
                        <div class="alert alert-danger small">
                            <ul class="mb-0">
                                @foreach ($errosDados->all() as $erro)
                                    <li>{{ $erro }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('admin.usuarios.dados', $usuario) }}" novalidate>
                        @csrf
                        @method('PATCH')
                        <div class="row g-2 mb-2">
                            <div class="col-md-7">
                                <label for="dados_nome" class="form-label small">Nome</label>
                                <input id="dados_nome" name="nome" class="form-control form-control-sm @error('nome', 'dados') is-invalid @enderror" value="{{ old('nome', $usuario->nome) }}" maxlength="150" required>
                            </div>
                            <div class="col-md-5">
                                <label for="dados_telefone" class="form-label small">Telefone{{ $usuario->perfil === Perfil::Demandante ? '' : ' (opcional)' }}</label>
                                <input id="dados_telefone" name="telefone" class="form-control form-control-sm @error('telefone', 'dados') is-invalid @enderror" value="{{ old('telefone', $usuario->telefone) }}" data-mascara="telefone">
                            </div>
                        </div>

                        @if ($usuario->perfil === Perfil::Estudante)
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <label for="dados_matricula" class="form-label small">Matrícula</label>
                                    <input id="dados_matricula" name="matricula" class="form-control form-control-sm @error('matricula', 'dados') is-invalid @enderror" value="{{ old('matricula', $usuario->estudante?->matricula) }}" maxlength="20" inputmode="numeric" required>
                                </div>
                                <div class="col-md-7">
                                    <label for="dados_curso" class="form-label small">Curso</label>
                                    <select id="dados_curso" name="curso_id" class="form-select form-select-sm @error('curso_id', 'dados') is-invalid @enderror">
                                        @foreach ($cursosDoEstudante as $curso)
                                            <option value="{{ $curso->id }}" @selected((string) old('curso_id', $usuario->estudante?->curso_id) === (string) $curso->id)>{{ $curso->nome }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @elseif ($usuario->perfil === Perfil::Demandante)
                            <div class="row g-2 mb-2">
                                <div class="col-md-7">
                                    <label for="dados_escola" class="form-label small">Comunidade/instituição</label>
                                    <select id="dados_escola" name="escola_instituicao_id" class="form-select form-select-sm @error('escola_instituicao_id', 'dados') is-invalid @enderror">
                                        @foreach ($escolasDoDemandante as $opcaoEscola)
                                            <option value="{{ $opcaoEscola->id }}" @selected((string) old('escola_instituicao_id', $usuario->demandante?->escola_instituicao_id) === (string) $opcaoEscola->id)>{{ $opcaoEscola->nomeCompleto() }}{{ $opcaoEscola->ativo ? '' : ' (nova, a conferir)' }}</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Trocar a comunidade/instituição muda só este demandante. As demandas já abertas continuam com a comunidade/instituição da abertura.</div>
                                </div>
                                <div class="col-md-5">
                                    <label for="dados_cargo" class="form-label small">Cargo</label>
                                    <input id="dados_cargo" name="cargo" class="form-control form-control-sm @error('cargo', 'dados') is-invalid @enderror" value="{{ old('cargo', $usuario->demandante?->cargo) }}" maxlength="100" required>
                                </div>
                            </div>
                        @elseif ($usuario->perfil === Perfil::Coordenador)
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <label for="dados_siape" class="form-label small">SIAPE (opcional)</label>
                                    <input id="dados_siape" name="siape" class="form-control form-control-sm @error('siape', 'dados') is-invalid @enderror" value="{{ old('siape', $usuario->coordenador?->siape) }}" maxlength="20" inputmode="numeric">
                                </div>
                                <div class="col-md-7">
                                    <label for="dados_departamento" class="form-label small">Departamento (opcional)</label>
                                    <input id="dados_departamento" name="departamento" class="form-control form-control-sm @error('departamento', 'dados') is-invalid @enderror" value="{{ old('departamento', $usuario->coordenador?->departamento) }}" maxlength="150">
                                </div>
                            </div>
                        @endif

                        <div class="form-text mb-2">O e-mail não muda aqui, porque é o login do usuário.</div>
                        <button type="submit" class="btn btn-sm btn-primary">Salvar dados</button>
                    </form>
                </div>
            </div>

            {{-- E11 do teste completo: só a comunidade/instituição nova, ainda não conferida; a conferida vale para todos os que a usam. --}}
            @if ($escola && ! $escola->ativo && ! $proprioUsuario)
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Conferir comunidade/instituição nova</div>
                    <div class="card-body">
                        <p class="small text-muted">Comunidade/instituição digitada pelo demandante. Corrija os dados se precisar; ela passa a aparecer na lista quando o cadastro for aprovado.</p>
                        <form method="POST" action="{{ route('admin.usuarios.escola', $usuario) }}" novalidate>
                            @csrf
                            @method('PATCH')
                            <div class="mb-2">
                                <label for="escola_nome" class="form-label small">Nome</label>
                                <input id="escola_nome" name="nome" class="form-control form-control-sm" value="{{ old('nome', $escola->nome) }}" maxlength="200" required>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label for="escola_tipo" class="form-label small">Tipo</label>
                                    <select id="escola_tipo" name="tipo" class="form-select form-select-sm">
                                        @foreach ($tiposEscola as $valor => $rotulo)
                                            <option value="{{ $valor }}" @selected($escola->tipo->value === $valor)>{{ $rotulo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="escola_inep" class="form-label small">Código INEP <span class="text-muted">(só para escolas)</span></label>
                                    <input id="escola_inep" name="codigo_inep" class="form-control form-control-sm" value="{{ old('codigo_inep', $escola->codigo_inep) }}" maxlength="8" inputmode="numeric">
                                </div>
                                <div class="col-md-3">
                                    <label for="escola_cidade" class="form-label small">Cidade</label>
                                    <input id="escola_cidade" name="cidade" class="form-control form-control-sm" value="{{ old('cidade', $escola->cidade) }}" maxlength="100" required>
                                </div>
                                <div class="col-md-2">
                                    <label for="escola_uf" class="form-label small">UF</label>
                                    <input id="escola_uf" name="uf" class="form-control form-control-sm text-uppercase" value="{{ old('uf', $escola->uf) }}" maxlength="2" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary">Salvar dados da comunidade/instituição</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        @unless ($proprioUsuario)
            <div class="col-lg-5">
                @if ($pendente)
                    <div class="card shadow-sm mb-3 border-warning">
                        <div class="card-header bg-warning-subtle fw-semibold">Aprovação do cadastro</div>
                        <div class="card-body">
                            @if ($usuario->hasVerifiedEmail())
                                <form method="POST" action="{{ route('admin.usuarios.status', $usuario) }}" class="mb-3">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ StatusUsuario::Ativo->value }}">
                                    <button type="submit" class="btn btn-success w-100">Aprovar cadastro</button>
                                </form>
                            @else
                                <p class="small text-muted">O usuário ainda não confirmou o e-mail. A aprovação fica liberada depois da confirmação.</p>
                            @endif

                            <form method="POST" action="{{ route('admin.usuarios.status', $usuario) }}" data-confirmar="Rejeitar o cadastro de {{ $usuario->nome }}? O usuário recebe um e-mail com o motivo.">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ StatusUsuario::Rejeitado->value }}">
                                <label for="motivo_rejeicao" class="form-label small">Motivo da rejeição</label>
                                <textarea id="motivo_rejeicao" name="motivo_status" class="form-control mb-2" rows="2" maxlength="1000" required>{{ old('motivo_status') }}</textarea>
                                <button type="submit" class="btn btn-outline-danger w-100">Rejeitar cadastro</button>
                            </form>
                        </div>
                    </div>
                @endif

                @if ($coordenacaoAtual && ($coordenacaoAtual['projetos'] || $coordenacaoAtual['subprojetos']))
                    <div class="alert alert-warning small">
                        Este coordenador é responsável por {{ $coordenacaoAtual['projetos'] }} Projeto(s) Base e {{ $coordenacaoAtual['subprojetos'] }} sub-projeto(s) em andamento.
                        Se ele for inativado ou mudar de perfil, eles passam para a administração, e você escolhe outro coordenador depois.
                        Os sub-projetos de demandas encerradas continuam no nome dele.
                    </div>
                @endif

                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Alterar status</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.usuarios.status', $usuario) }}" data-confirmar-status data-status-atual="{{ $usuario->status->rotulo() }}">
                            @csrf
                            @method('PATCH')
                            <label for="status" class="form-label small">Novo status</label>
                            <select id="status" name="status" class="form-select mb-2" data-campo-motivo="#bloco-motivo-status">
                                @foreach ($statusOpcoes as $valor => $rotulo)
                                    <option value="{{ $valor }}" data-exige-motivo="{{ StatusUsuario::from($valor)->exigeMotivo() ? '1' : '0' }}" @selected($usuario->status->value === $valor)>{{ $rotulo }}</option>
                                @endforeach
                            </select>
                            <div id="bloco-motivo-status" class="d-none">
                                <label for="motivo_status" class="form-label small">Motivo</label>
                                <textarea id="motivo_status" name="motivo_status" class="form-control mb-2" rows="2" maxlength="1000"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Salvar status</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Trocar perfil</div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.usuarios.perfil', $usuario) }}" data-confirmar-perfil data-perfil-atual="{{ $usuario->perfil->rotulo() }}" data-perfil-atual-valor="{{ $usuario->perfil->value }}" novalidate>
                            @csrf
                            @method('PATCH')
                            <p class="small mb-2">Perfil atual: <strong>{{ $usuario->perfil->rotulo() }}</strong></p>
                            <p class="small text-muted mb-2">Só para mudar o perfil. Para corrigir os dados do perfil atual, use <a href="#editar-dados">"Editar dados"</a>.</p>
                            <label for="perfil" class="form-label small">Novo perfil</label>
                            <select id="perfil" name="perfil" class="form-select mb-2" data-campos-perfil>
                                @foreach ($perfis as $valor => $rotulo)
                                    <option value="{{ $valor }}" @selected(old('perfil', $usuario->perfil->value) === $valor)>{{ $rotulo }}</option>
                                @endforeach
                            </select>

                            <div data-perfil-campos="estudante" class="d-none">
                                <label for="perfil_matricula" class="form-label small">Matrícula</label>
                                <input id="perfil_matricula" name="matricula" class="form-control form-control-sm mb-2" value="{{ old('matricula', $usuario->estudante?->matricula) }}" maxlength="20" inputmode="numeric">
                                <label for="perfil_curso" class="form-label small">Curso</label>
                                <select id="perfil_curso" name="curso_id" class="form-select form-select-sm mb-2">
                                    <option value="">Selecione</option>
                                    @foreach ($cursos as $curso)
                                        <option value="{{ $curso->id }}" @selected(old('curso_id', $usuario->estudante?->curso_id) == $curso->id)>{{ $curso->nome }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div data-perfil-campos="demandante" class="d-none">
                                <label for="perfil_escola" class="form-label small">Comunidade/instituição</label>
                                <select id="perfil_escola" name="escola_instituicao_id" class="form-select form-select-sm mb-2">
                                    <option value="">Selecione</option>
                                    @foreach ($escolas as $opcaoEscola)
                                        <option value="{{ $opcaoEscola->id }}" @selected(old('escola_instituicao_id', $usuario->demandante?->escola_instituicao_id) == $opcaoEscola->id)>{{ $opcaoEscola->nomeCompleto() }}</option>
                                    @endforeach
                                </select>
                                <label for="perfil_cargo" class="form-label small">Cargo</label>
                                <input id="perfil_cargo" name="cargo" class="form-control form-control-sm mb-2" value="{{ old('cargo', $usuario->demandante?->cargo) }}" maxlength="100">
                                <label for="perfil_telefone" class="form-label small">Telefone</label>
                                <input id="perfil_telefone" name="telefone" class="form-control form-control-sm mb-2" value="{{ old('telefone', $usuario->telefone) }}" data-mascara="telefone">
                            </div>

                            <div data-perfil-campos="coordenador" class="d-none">
                                <label for="perfil_siape" class="form-label small">SIAPE (opcional)</label>
                                <input id="perfil_siape" name="siape" class="form-control form-control-sm mb-2" value="{{ old('siape', $usuario->coordenador?->siape) }}" maxlength="20" inputmode="numeric">
                                <label for="perfil_departamento" class="form-label small">Departamento (opcional)</label>
                                <input id="perfil_departamento" name="departamento" class="form-control form-control-sm mb-2" value="{{ old('departamento', $usuario->coordenador?->departamento) }}" maxlength="150">
                            </div>

                            <button type="submit" class="btn btn-outline-primary w-100">Trocar perfil</button>
                        </form>
                    </div>
                </div>
            </div>
        @endunless
    </div>
</div>
@endsection
