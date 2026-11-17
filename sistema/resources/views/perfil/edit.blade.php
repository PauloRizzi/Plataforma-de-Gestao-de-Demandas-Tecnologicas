@extends('layouts.app')

@section('titulo', 'Meu perfil')

@php
    use App\Enums\Perfil;

    $abaSenha = $errors->senha->any();
@endphp

@section('content')
<div class="container">
    <h1 class="h3 mb-3">Meu perfil</h1>

    <div class="row g-3">
        {{-- Foto de perfil: sem foto, aparece o avatar padrão do perfil. --}}
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <img src="{{ $usuario->urlFoto() }}" alt="Sua foto de perfil" class="avatar avatar-lg border mb-3" data-previa-imagem="foto" data-imagem-padrao="{{ asset("images/avatares/{$usuario->perfil->value}.svg") }}">
                    <div class="fw-semibold">{{ $usuario->nome }}</div>
                    <div class="small text-muted">{{ $usuario->perfil->rotulo() }}</div>
                    <div class="small text-muted text-break">{{ $usuario->email }}</div>

                    <form method="POST" action="{{ route('perfil.foto') }}" enctype="multipart/form-data" class="text-start mt-3" data-enviando="Enviando..." novalidate>
                        @csrf
                        <label for="foto" class="form-label small">{{ $usuario->foto ? 'Trocar a foto' : 'Enviar uma foto' }}</label>
                        <input id="foto" type="file" name="foto" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="form-control form-control-sm @error('foto') is-invalid @enderror" required>
                        <div class="form-text">
                            JPG, PNG ou WebP, até {{ \App\Models\Usuario::IMAGEM_TAMANHO_MAXIMO_MB }} MB. A foto é recortada num quadrado, pelo centro.
                            Aparece só para quem está logado no sistema.
                        </div>
                        @error('foto')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <button type="submit" class="btn btn-primary btn-sm w-100 mt-2">Salvar foto</button>
                    </form>

                    @if ($usuario->foto)
                        <form method="POST" action="{{ route('perfil.foto.remover') }}" class="mt-2" data-confirmar="Remover a sua foto? No lugar dela aparece o avatar padrão do seu perfil.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">Remover foto</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @unless ($abaSenha) active @endunless" id="aba-dados" data-bs-toggle="tab" data-bs-target="#painel-dados" type="button" role="tab" aria-controls="painel-dados" aria-selected="{{ $abaSenha ? 'false' : 'true' }}">Meus dados</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if ($abaSenha) active @endif" id="aba-senha" data-bs-toggle="tab" data-bs-target="#painel-senha" type="button" role="tab" aria-controls="painel-senha" aria-selected="{{ $abaSenha ? 'true' : 'false' }}">Senha</button>
                        </li>
                    </ul>
                </div>

                <div class="card-body tab-content">
                    {{-- Dados da conta e do perfil. --}}
                    <div class="tab-pane fade @unless ($abaSenha) show active @endunless" id="painel-dados" role="tabpanel" aria-labelledby="aba-dados" tabindex="0">
                        <form method="POST" action="{{ route('perfil.update') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <div class="mb-3">
                                <label for="nome" class="form-label">Nome completo <span class="text-danger">*</span></label>
                                <input id="nome" type="text" name="nome" class="form-control @error('nome') is-invalid @enderror" value="{{ old('nome', $usuario->nome) }}" required maxlength="150" autocomplete="name">
                                @error('nome')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-7">
                                    <label for="email" class="form-label">E-mail</label>
                                    <input id="email" type="email" class="form-control" value="{{ $usuario->email }}" disabled>
                                    <div class="form-text">O e-mail é o seu login. Para trocar, fale com a coordenação do projeto.</div>
                                </div>
                                <div class="col-md-5">
                                    <label for="telefone" class="form-label">
                                        Telefone
                                        @if ($usuario->temPerfil(Perfil::Demandante))
                                            <span class="text-danger">*</span>
                                        @else
                                            <span class="text-muted">(opcional)</span>
                                        @endif
                                    </label>
                                    <input id="telefone" type="tel" name="telefone" class="form-control @error('telefone') is-invalid @enderror" value="{{ old('telefone', $usuario->telefone) }}" data-mascara="telefone" placeholder="(28) 99999-9999" autocomplete="tel">
                                    @error('telefone')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            @if ($usuario->temPerfil(Perfil::Estudante) && $usuario->estudante)
                                <div class="row g-3 mb-3">
                                    <div class="col-md-5">
                                        <label for="matricula" class="form-label">Matrícula</label>
                                        <input id="matricula" type="text" class="form-control" value="{{ $usuario->estudante->matricula }}" disabled>
                                        <div class="form-text">Para corrigir, fale com a coordenação.</div>
                                    </div>
                                    <div class="col-md-7">
                                        <label for="curso_id" class="form-label">Curso <span class="text-danger">*</span></label>
                                        <select id="curso_id" name="curso_id" class="form-select @error('curso_id') is-invalid @enderror" required>
                                            @foreach ($cursos as $curso)
                                                <option value="{{ $curso->id }}" @selected((string) old('curso_id', $usuario->estudante->curso_id) === (string) $curso->id)>{{ $curso->nome }}</option>
                                            @endforeach
                                        </select>
                                        @error('curso_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            @endif

                            @if ($usuario->temPerfil(Perfil::Demandante) && $usuario->demandante)
                                <div class="row g-3 mb-3">
                                    <div class="col-md-5">
                                        <label for="cargo" class="form-label">Cargo ou função <span class="text-danger">*</span></label>
                                        <input id="cargo" type="text" name="cargo" class="form-control @error('cargo') is-invalid @enderror" value="{{ old('cargo', $usuario->demandante->cargo) }}" required maxlength="100">
                                        @error('cargo')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-7">
                                        <label for="escola" class="form-label">Comunidade ou instituição</label>
                                        <input id="escola" type="text" class="form-control" value="{{ $usuario->demandante->escolaInstituicao->nomeCompleto() }}" disabled>
                                        <div class="form-text">Para trocar de comunidade/instituição, fale com a coordenação.</div>
                                    </div>
                                </div>
                            @endif

                            @if ($usuario->temPerfil(Perfil::Coordenador) && $usuario->coordenador)
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <label for="siape" class="form-label">SIAPE <span class="text-muted">(opcional)</span></label>
                                        <input id="siape" type="text" name="siape" inputmode="numeric" class="form-control @error('siape') is-invalid @enderror" value="{{ old('siape', $usuario->coordenador->siape) }}" maxlength="20">
                                        @error('siape')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-8">
                                        <label for="departamento" class="form-label">Departamento <span class="text-muted">(opcional)</span></label>
                                        <input id="departamento" type="text" name="departamento" class="form-control @error('departamento') is-invalid @enderror" value="{{ old('departamento', $usuario->coordenador->departamento) }}" maxlength="150">
                                        @error('departamento')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            @endif

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">Salvar dados</button>
                            </div>
                        </form>
                    </div>

                    {{-- Troca de senha: pede a senha atual. --}}
                    <div class="tab-pane fade @if ($abaSenha) show active @endif" id="painel-senha" role="tabpanel" aria-labelledby="aba-senha" tabindex="0">
                        <form method="POST" action="{{ route('perfil.senha') }}" novalidate>
                            @csrf
                            @method('PUT')

                            <div class="mb-3">
                                <label for="senha_atual" class="form-label">Senha atual</label>
                                <input id="senha_atual" type="password" name="senha_atual" class="form-control @error('senha_atual', 'senha') is-invalid @enderror" required autocomplete="current-password">
                                @error('senha_atual', 'senha')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="password" class="form-label">Nova senha</label>
                                    <input id="password" type="password" name="password" class="form-control @error('password', 'senha') is-invalid @enderror" required autocomplete="new-password">
                                    <div class="form-text">Mínimo de 8 caracteres, com letras e números.</div>
                                    @error('password', 'senha')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="password-confirm" class="form-label">Confirme a nova senha</label>
                                    <input id="password-confirm" type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">Alterar senha</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
