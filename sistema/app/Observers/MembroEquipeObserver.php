<?php

namespace App\Observers;

use App\Enums\TipoHistorico;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Notifications\Aviso;
use Illuminate\Support\Facades\Auth;

/**
 * RF08 e RF11: registra na linha do tempo da demanda a entrada, a troca de papel e a remoção
 * de cada membro da equipe. Guarda os nomes, para a linha do tempo não mudar se um papel for renomeado.
 */
class MembroEquipeObserver
{
    public function created(MembroEquipe $membro): void
    {
        $this->registrar($membro, "{$membro->estudante->nome} entrou na equipe como {$membro->papel->nome}", [
            'acao' => 'entrada',
            'papel' => $membro->papel->nome,
        ]);

        // Fase 9: incluído direto pela coordenação, sem candidatura (quem tem candidatura recebe o aviso da aprovação).
        if ($membro->candidatura_id === null) {
            $subprojeto = $membro->subprojeto;

            Aviso::enviar([$membro->estudante], new Aviso(
                "Você entrou na equipe: {$subprojeto->titulo}",
                ["A coordenação incluiu você na equipe do sub-projeto \"{$subprojeto->titulo}\" como {$membro->papel->nome}."],
                'Ver o sub-projeto',
                route('subprojetos.show', $subprojeto),
            ));
        }
    }

    public function updated(MembroEquipe $membro): void
    {
        // Estudante removido que voltou para a equipe (inclusão pela coordenação ou candidatura aprovada).
        if ($membro->wasChanged('removido_em') && ! $membro->foiRemovido()) {
            $this->registrar($membro, "{$membro->estudante->nome} voltou para a equipe como {$membro->papel()->first()->nome}", [
                'acao' => 'retorno',
                'papel' => $membro->papel()->first()->nome,
            ]);

            // Quem voltou por uma candidatura nova recebe o aviso da aprovação.
            if (! $membro->wasChanged('candidatura_id')) {
                $subprojeto = $membro->subprojeto;

                Aviso::enviar([$membro->estudante], new Aviso(
                    "Você voltou para a equipe: {$subprojeto->titulo}",
                    ["A coordenação incluiu você de novo na equipe do sub-projeto \"{$subprojeto->titulo}\" como {$membro->papel()->first()->nome}."],
                    'Ver o sub-projeto',
                    route('subprojetos.show', $subprojeto),
                ));
            }

            return;
        }

        if ($membro->wasChanged('papel_id')) {
            $anterior = PapelEquipe::find($membro->getRawOriginal('papel_id'))?->nome;
            $novo = $membro->papel()->first()->nome;

            $this->registrar($membro, "Papel de {$membro->estudante->nome} alterado de {$anterior} para {$novo}", [
                'acao' => 'papel',
                'de' => $anterior,
                'para' => $novo,
            ]);
        }

        if ($membro->wasChanged('removido_em') && $membro->foiRemovido()) {
            $this->registrar($membro, "{$membro->estudante->nome} foi removido(a) da equipe", ['acao' => 'remocao']);
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function registrar(MembroEquipe $membro, string $descricao, array $dados): void
    {
        $membro->subprojeto->solicitacao->historicos()->create([
            'usuario_id' => Auth::id(),
            'tipo' => TipoHistorico::Equipe,
            'descricao' => $descricao,
            'dados' => ['estudante_id' => $membro->estudante_id, ...$dados],
        ]);
    }
}
