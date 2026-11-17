{{-- RF08: linha do tempo. Fase 9: a lista completa abre numa janela, para a página não ficar extensa. --}}
@php($ultimo = $solicitacao->historicos->last())

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h6 text-muted">Linha do tempo</h2>
        @if ($ultimo)
            <div class="small text-muted mb-1">Último registro</div>
            <div class="d-flex gap-2 mb-3">
                <span class="ponto-linha-do-tempo bg-{{ $ultimo->tipo->cor() }} mt-1"></span>
                <div>
                    <div class="small fw-semibold">{{ $ultimo->descricao }}</div>
                    <div class="small text-muted">{{ $ultimo->usuario?->nome ?? 'Sistema' }} &middot; {{ $ultimo->created_at->format('d/m/Y H:i') }}</div>
                </div>
            </div>
        @endif
        <button type="button" class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modal-linha-do-tempo">
            Ver a linha do tempo completa ({{ $solicitacao->historicos->count() }} {{ $solicitacao->historicos->count() === 1 ? 'registro' : 'registros' }})
        </button>
    </div>
</div>

<div class="modal fade" id="modal-linha-do-tempo" tabindex="-1" aria-labelledby="modal-linha-do-tempo-titulo" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="modal-linha-do-tempo-titulo">Linha do tempo · {{ $solicitacao->titulo }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <ol class="linha-do-tempo">
                    @foreach ($solicitacao->historicos as $historico)
                        <li>
                            <span class="ponto bg-{{ $historico->tipo->cor() }}"></span>
                            <div class="small fw-semibold">{{ $historico->descricao }}</div>

                            @if ($historico->tipo->listaAlteracoes())
                                <ul class="list-unstyled small mb-0">
                                    @foreach ($historico->dados ?? [] as $campo => $valores)
                                        <li class="p-0">
                                            {{ ucfirst($campo) }}:
                                            @if ($valores['de'] ?? null)
                                                <span class="text-muted text-decoration-line-through">{{ $valores['de'] }}</span> &rarr;
                                            @endif
                                            {{ $valores['para'] ?? '—' }}
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (isset($historico->dados['motivo']))
                                <div class="small">Motivo: {{ $historico->dados['motivo'] }}</div>
                            @elseif (isset($historico->dados['feedback']))
                                <div class="small" style="white-space: pre-line">Comentário: {{ $historico->dados['feedback'] }}</div>
                            @endif

                            <div class="small text-muted">
                                {{ $historico->usuario?->nome ?? 'Sistema' }} &middot; {{ $historico->created_at->format('d/m/Y H:i') }}
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
