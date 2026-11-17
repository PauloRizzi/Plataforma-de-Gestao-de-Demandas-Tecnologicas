@extends('layouts.app')

@section('titulo', 'Oportunidades')

@section('content')
<div class="container">
    <div class="mb-3">
        <h1 class="h3 mb-0">Oportunidades</h1>
        <p class="text-muted small mb-0">
            Sub-projetos com vagas abertas para estudantes extensionistas. Escolha um e envie a sua candidatura.
            @if (auth()->user()->temPerfil(\App\Enums\Perfil::Estudante))
                Aparecem só as vagas dos projetos de extensão a que você está vinculado.
            @endif
        </p>
    </div>

    {{-- Fase 9: só o estudante vinculado ao Projeto Base se candidata às vagas do projeto. --}}
    @if ($semVinculo)
        <div class="alert alert-info small">
            Você ainda não está vinculado a nenhum projeto de extensão. O vínculo é feito pela coordenação do projeto; depois dele, as vagas do projeto aparecem aqui.
        </div>
    @endif

    @include('oportunidades._filtros', ['acao' => route('oportunidades.index')])

    <div class="row g-3">
        @forelse ($subprojetos as $subprojeto)
            <div class="col-md-6 col-xl-4">
                @include('oportunidades._card', ['publico' => false])
            </div>
        @empty
            <div class="col-12">
                <div class="card card-body text-center text-muted py-5">
                    @if (array_filter($filtros))
                        Nenhum sub-projeto encontrado com esses filtros.
                    @else
                        Nenhum sub-projeto com vagas abertas no momento. Volte em breve.
                    @endif
                </div>
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        {{ $subprojetos->links() }}
    </div>
</div>
@endsection
