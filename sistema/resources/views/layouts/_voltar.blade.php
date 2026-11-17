{{-- Fase 9: botão "Voltar" padrão do topo das telas. O destino aparece só para leitores de tela e na dica. --}}
<a href="{{ $url }}" class="btn btn-sm btn-outline-secondary" title="Voltar para {{ $destino }}" aria-label="Voltar para {{ $destino }}">
    <span aria-hidden="true">&larr;</span> Voltar
</a>
