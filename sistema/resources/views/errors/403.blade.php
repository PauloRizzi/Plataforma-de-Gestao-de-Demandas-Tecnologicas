@extends('layouts.app')

@section('titulo', 'Acesso negado')

{{-- E8 do teste completo: explica o que pode ter acontecido, em vez de só "Acesso negado". --}}
@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4">Esta ação não está disponível</h1>
                    @if ($exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.')
                        <p>{{ $exception->getMessage() }}</p>
                    @else
                        <p>Você não tem permissão para esta página ou ação, ou ela não vale mais na situação atual.</p>
                    @endif
                    <p class="small text-muted">
                        Se você clicou duas vezes, ou se a página estava aberta em outra aba, a ação pode já ter sido feita.
                        Volte e recarregue a página para ver a situação atual.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="btn btn-primary">Voltar</a>
                        <a href="{{ url('/') }}" class="btn btn-outline-secondary">Ir para o início</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
