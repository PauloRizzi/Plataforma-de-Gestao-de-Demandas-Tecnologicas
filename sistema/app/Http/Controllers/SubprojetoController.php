<?php

namespace App\Http\Controllers;

use App\Enums\Modalidade;
use App\Enums\Perfil;
use App\Enums\SituacaoEntrega;
use App\Enums\StatusCandidatura;
use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Models\Candidatura;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Subprojeto;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * RF09: conversão da demanda em sub-projeto do projeto de extensão. RF11: tela da equipe e das candidaturas.
 * Fase 9: a coordenação também inclui estudantes na equipe direto no formulário, sem candidatura.
 */
class SubprojetoController extends Controller
{
    /** Limites do formulário. */
    public const CARGA_HORARIA_MAXIMA = 2000;

    public const VAGAS_MAXIMAS = 50;

    /**
     * Coordenador e admin: sub-projetos que gerenciam. Estudante: as suas equipes e candidaturas.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Subprojeto::class);

        $usuario = $request->user();

        // Fase 9: busca e filtros, para quando houver muitos sub-projetos.
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'projeto' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(StatusDemanda::class)],
            'modalidade' => ['nullable', Rule::enum(Modalidade::class)],
        ]);

        $subprojetos = Subprojeto::query()
            ->with('solicitacao.projetoBase')
            ->withCount([
                'membrosAtivos',
                'candidaturas as candidaturas_pendentes_count' => fn ($query) => $query->where('status', StatusCandidatura::Pendente),
                'entregas as entregas_para_validar_count' => fn ($query) => $query->where('situacao', SituacaoEntrega::AguardandoTecnica),
            ])
            ->visiveisPara($usuario)
            ->filtrar($filtros)
            ->latest()
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('subprojetos.index', [
            'subprojetos' => $subprojetos,
            'filtros' => $filtros,
            'filtrando' => array_filter($filtros) !== [],
            // Só os projetos que têm algum sub-projeto visível para o usuário.
            'projetos' => ProjetoBase::query()
                ->whereIn('id', Solicitacao::query()
                    ->whereHas('subprojeto', fn ($query) => $query->visiveisPara($usuario))
                    ->select('projeto_base_id'))
                ->orderBy('titulo')
                ->get(['id', 'titulo']),
            'statusOpcoes' => StatusDemanda::cases(),
            'modalidades' => Modalidade::cases(),
            'ehEstudante' => $usuario->temPerfil(Perfil::Estudante),
            // Fase 9: o estudante vê os projetos de extensão a que está vinculado.
            'meusProjetos' => $usuario->temPerfil(Perfil::Estudante)
                ? $usuario->projetosVinculados()->get()
                : collect(),
            'minhasCandidaturas' => $usuario->temPerfil(Perfil::Estudante)
                ? Candidatura::query()
                    ->with('subprojeto.solicitacao.projetoBase')
                    ->where('estudante_id', $usuario->id)
                    ->latest()
                    ->orderByDesc('id')
                    ->get()
                : collect(),
        ]);
    }

    /**
     * Formulário pré-preenchido com os dados da demanda.
     */
    public function create(Solicitacao $solicitacao): View
    {
        Gate::authorize('create', [Subprojeto::class, $solicitacao]);

        $subprojeto = new Subprojeto([
            'titulo' => $solicitacao->titulo,
            'escopo' => $solicitacao->descricao,
            'data_inicio' => now(),
            'vagas' => 1,
            'candidaturas_abertas' => true,
        ]);

        return view('subprojetos.form', [
            'subprojeto' => $subprojeto,
            'solicitacao' => $solicitacao,
            'modalidades' => Modalidade::cases(),
            'editaDados' => true,
            ...$this->dadosDaEquipe($subprojeto, $solicitacao),
        ]);
    }

    public function store(Request $request, Solicitacao $solicitacao): RedirectResponse
    {
        Gate::authorize('create', [Subprojeto::class, $solicitacao]);

        $dados = $this->validar($request, $solicitacao);

        // O coordenador logado vira o responsável. Se for o admin, fica o coordenador do projeto (quando houver).
        $usuario = $request->user();
        $responsavel = $usuario->temPerfil(Perfil::Coordenador)
            ? $usuario->id
            : ($solicitacao->projetoBase->coordenador_id ?? $usuario->id);

        DB::transaction(function () use ($solicitacao, $dados, $responsavel, $usuario): void {
            $subprojeto = $solicitacao->subprojeto()->create([
                ...collect($dados)->except(['estudantes', 'papel_id'])->all(),
                'coordenador_id' => $responsavel,
                'candidaturas_abertas' => true,
            ]);

            $this->incluirEstudantes($subprojeto, $dados, $usuario);
        });

        return redirect()->route('demandas.show', $solicitacao)
            ->with('sucesso', 'Sub-projeto criado e aberto para candidaturas na vitrine. Se a equipe já pode começar, mude a demanda para "Em Desenvolvimento".');
    }

    public function show(Request $request, Subprojeto $subprojeto): View
    {
        Gate::authorize('view', $subprojeto);

        $subprojeto->load([
            'solicitacao.projetoBase',
            'coordenador',
            'membros' => fn ($query) => $query->orderByRaw('removido_em is not null')->orderBy('created_at'),
            'membros.estudante.estudante.curso',
            'membros.papel',
            'candidaturas' => fn ($query) => $query->orderBy('created_at'),
            'candidaturas.estudante.estudante.curso',
            'candidaturas.avaliador',
        ])->loadCount('membrosAtivos');

        $papeis = PapelEquipe::query()->ativos()->get();
        $usuario = $request->user();

        // RF12: o demandante só vê as entregas que já passaram pela aprovação técnica.
        $entregas = $subprojeto->entregas()
            ->with('remetente')
            ->when(
                $subprojeto->solicitacao->demandante_id === $usuario->id && ! Gate::allows('gerencia', $subprojeto->solicitacao),
                fn ($query) => $query->visiveisAoDemandante(),
            )
            ->get();

        return view('subprojetos.show', [
            'subprojeto' => $subprojeto,
            'entregas' => $entregas,
            'ehMembro' => $usuario->temPerfil(Perfil::Estudante) && $subprojeto->ehMembroAtivo($usuario),
            // Membros da equipe e, em nome dela, quem gerencia a demanda (decisão (b) da Fase 6).
            'podeEnviarEntrega' => ($usuario->temPerfil(Perfil::Estudante) && $subprojeto->ehMembroAtivo($usuario))
                || Gate::allows('gerencia', $subprojeto->solicitacao),
            'impedimentoDeEntrega' => $subprojeto->impedimentoDeEntrega($usuario),
            'papeis' => $papeis,
            'papelPadrao' => $papeis->firstWhere('nome', PapelEquipe::PADRAO) ?? $papeis->first(),
            'gerencia' => Gate::allows('gerenciarEquipe', $subprojeto),
            // Fase 9: candidatura de quem não está vinculado ao Projeto Base não pode ser aprovada.
            'vinculados' => $subprojeto->solicitacao->projetoBase->estudantes()->pluck('usuarios.id')->all(),
        ]);
    }

    public function edit(Subprojeto $subprojeto): View
    {
        Gate::authorize('alterarCargaHoraria', $subprojeto);

        $editaDados = Gate::allows('update', $subprojeto);

        return view('subprojetos.form', [
            'subprojeto' => $subprojeto,
            'solicitacao' => $subprojeto->solicitacao,
            'modalidades' => Modalidade::cases(),
            'editaDados' => $editaDados,
            ...($editaDados ? $this->dadosDaEquipe($subprojeto, $subprojeto->solicitacao) : []),
        ]);
    }

    /**
     * Com a demanda encerrada, só a carga horária estimada (RF15) e o término podem ser corrigidos:
     * o sub-projeto pode se estender além do previsto (Fase 9).
     */
    public function update(Request $request, Subprojeto $subprojeto): RedirectResponse
    {
        Gate::authorize('alterarCargaHoraria', $subprojeto);

        $editaDados = Gate::allows('update', $subprojeto);

        if ($editaDados) {
            $dados = $this->validar($request, $subprojeto->solicitacao, $subprojeto);
            $dados['candidaturas_abertas'] = $request->boolean('candidaturas_abertas');
        } else {
            $inicio = $subprojeto->data_inicio;
            $dados = $request->validate([
                'data_fim' => ['sometimes', 'required', 'date', 'after_or_equal:'.$inicio->format('Y-m-d')],
                'carga_horaria_estimada' => ['required', 'integer', 'min:1', 'max:'.self::CARGA_HORARIA_MAXIMA],
            ], [
                'data_fim.after_or_equal' => "A data de término não pode ser anterior ao início ({$inicio->format('d/m/Y')}).",
            ], ['data_fim' => 'data de término', 'carga_horaria_estimada' => 'carga horária estimada']);
        }

        // A alteração, a entrada dos estudantes e os registros no histórico são gravados juntos ou nenhum.
        DB::transaction(function () use ($subprojeto, $dados, $editaDados, $request): void {
            $subprojeto->updateOrFail(collect($dados)->except(['estudantes', 'papel_id'])->all());

            if ($editaDados) {
                $this->incluirEstudantes($subprojeto, $dados, $request->user());
            }
        });

        return redirect()->route('subprojetos.show', $subprojeto)->with('sucesso', 'Sub-projeto atualizado.');
    }

    /**
     * Estudantes que podem ser incluídos na equipe (vinculados ao Projeto Base, ativos e fora dela)
     * e os papéis disponíveis.
     *
     * @return array{estudantes: Collection<int, Usuario>, papeis: Collection<int, PapelEquipe>, papelPadrao: ?PapelEquipe, projeto: ProjetoBase}
     */
    private function dadosDaEquipe(Subprojeto $subprojeto, Solicitacao $solicitacao): array
    {
        $papeis = PapelEquipe::query()->ativos()->get();

        return [
            'estudantes' => $this->estudantesVinculados($solicitacao)
                ->with('estudante.curso')
                ->when($subprojeto->exists, fn ($query) => $query->whereNotIn(
                    'id',
                    $subprojeto->membrosAtivos()->select('estudante_id'),
                ))
                ->orderBy('nome')
                ->get(),
            'papeis' => $papeis,
            'papelPadrao' => $papeis->firstWhere('nome', PapelEquipe::PADRAO) ?? $papeis->first(),
            'projeto' => $solicitacao->projetoBase,
        ];
    }

    /**
     * Fase 9: a coordenação só coloca na equipe os estudantes ativos vinculados ao Projeto Base da demanda.
     *
     * @return Builder<Usuario>
     */
    private function estudantesVinculados(Solicitacao $solicitacao): Builder
    {
        return Usuario::query()
            ->where('perfil', Perfil::Estudante)
            ->where('status', StatusUsuario::Ativo)
            ->whereHas('projetosVinculados', fn (Builder $query) => $query->whereKey($solicitacao->projeto_base_id));
    }

    /**
     * Coloca na equipe os estudantes escolhidos no formulário. Quem tinha candidatura pendente
     * tem a candidatura aprovada; os demais entram sem candidatura (inclusão pela coordenação).
     *
     * @param  array<string, mixed>  $dados
     */
    private function incluirEstudantes(Subprojeto $subprojeto, array $dados, Usuario $autor): void
    {
        $ids = array_map('intval', $dados['estudantes'] ?? []);

        if ($ids === []) {
            return;
        }

        // Trava o sub-projeto para a inclusão e uma aprovação ao mesmo tempo não passarem do número de vagas.
        $subprojeto = Subprojeto::query()->lockForUpdate()->findOrFail($subprojeto->id);

        if ($subprojeto->membrosAtivos()->whereIn('estudante_id', $ids)->exists()) {
            throw ValidationException::withMessages(['estudantes' => 'Um dos estudantes escolhidos já está na equipe. Atualize a página e escolha de novo.']);
        }

        $total = $subprojeto->membrosAtivos()->count() + count($ids);

        if ($total > $subprojeto->vagas) {
            throw ValidationException::withMessages(['estudantes' => "A equipe ficaria com {$total} estudantes, mas o sub-projeto tem {$subprojeto->vagas} vaga(s). Aumente o número de vagas."]);
        }

        foreach ($ids as $estudanteId) {
            $candidatura = $subprojeto->candidaturas()
                ->where('estudante_id', $estudanteId)
                ->where('status', StatusCandidatura::Pendente)
                ->first();

            $candidatura?->update([
                'status' => StatusCandidatura::Aprovada,
                'avaliado_por' => $autor->id,
                'avaliado_em' => now(),
            ]);

            // Quem já foi removido volta no mesmo registro (E1 do teste completo).
            MembroEquipe::colocarNaEquipe($subprojeto, $estudanteId, (int) $dados['papel_id'], $candidatura?->id);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, Solicitacao $solicitacao, ?Subprojeto $subprojeto = null): array
    {
        // As vagas não podem ficar abaixo do número de membros que já estão na equipe.
        $minimoDeVagas = max(1, $subprojeto?->membrosAtivos()->count() ?? 0);

        return $request->validate([
            'titulo' => ['required', 'string', 'max:150'],
            'escopo' => ['required', 'string', 'max:5000'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'carga_horaria_estimada' => ['required', 'integer', 'min:1', 'max:'.self::CARGA_HORARIA_MAXIMA],
            'vagas' => ['required', 'integer', 'min:'.$minimoDeVagas, 'max:'.self::VAGAS_MAXIMAS],
            'modalidade' => ['required', Rule::enum(Modalidade::class)],
            // Fase 9: estudantes incluídos direto pela coordenação, sem candidatura.
            'estudantes' => ['nullable', 'array', 'max:'.self::VAGAS_MAXIMAS],
            'estudantes.*' => ['integer', 'distinct', Rule::in($request->filled('estudantes')
                ? $this->estudantesVinculados($solicitacao)->pluck('id')->all()
                : [])],
            'papel_id' => ['nullable', 'required_with:estudantes', Rule::exists('papeis_equipe', 'id')->where('ativo', true)],
        ], [
            'data_fim.after_or_equal' => 'A data de término não pode ser anterior à data de início.',
            'estudantes.*.in' => 'Escolha estudantes com cadastro ativo e vinculados ao projeto de extensão.',
            'vagas.min' => $minimoDeVagas > 1
                ? "A equipe já tem {$minimoDeVagas} membros: as vagas não podem ser menos que isso."
                : 'Informe pelo menos 1 vaga.',
        ], [
            'titulo' => 'título',
            'data_inicio' => 'data de início',
            'data_fim' => 'data de término',
            'carga_horaria_estimada' => 'carga horária estimada',
            'papel_id' => 'papel na equipe',
        ]);
    }
}
