<?php

namespace App\Http\Controllers;

use App\Enums\Complexidade;
use App\Enums\Perfil;
use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Models\Anexo;
use App\Models\CategoriaDemanda;
use App\Models\Entrega;
use App\Models\EscolaInstituicao;
use App\Models\Prioridade;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Usuario;
use App\Policies\SolicitacaoPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * RF06: abertura e acompanhamento das demandas. RF07: fila, triagem e status iniciais.
 */
class SolicitacaoController extends Controller
{
    /**
     * Demandante: "Minhas demandas". Coordenador e admin: fila de triagem, que abre
     * nas demandas com status "Cadastrada".
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Solicitacao::class);

        $usuario = $request->user();
        $ehDemandante = $usuario->temPerfil(Perfil::Demandante);

        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StatusDemanda::class)],
            'projeto' => ['nullable', 'integer'],
            'prioridade' => ['nullable', 'integer'],
        ]);

        if (! $ehDemandante && ! $request->has('status')) {
            $filtros['status'] = StatusDemanda::Cadastrada->value;
        }

        $solicitacoes = Solicitacao::query()
            ->with(['projetoBase', 'escolaInstituicao', 'prioridade', 'categoria'])
            ->withCount('anexos')
            ->visiveisPara($usuario)
            ->filtrar($filtros)
            ->when($ehDemandante,
                fn ($query) => $query->latest()->orderByDesc('id'),
                fn ($query) => $query->ordemDaFila(),
            )
            ->paginate(15)
            ->withQueryString();

        return view('solicitacoes.index', [
            'solicitacoes' => $solicitacoes,
            'filtros' => $filtros,
            'ehDemandante' => $ehDemandante,
            'projetos' => $ehDemandante ? collect() : ProjetoBase::query()
                ->when(! $usuario->temPerfil(Perfil::Admin), fn ($query) => $query->where('coordenador_id', $usuario->id))
                ->orderBy('titulo')
                ->get(),
            'prioridades' => Prioridade::query()->ordenados()->get(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Solicitacao::class);

        $usuario = $request->user();
        $emNomeDaEscola = Gate::allows('criarEmNomeDaEscola', Solicitacao::class);

        return view('solicitacoes.create', [
            'projetos' => ProjetoBase::query()->disponiveis()
                ->when($usuario->temPerfil(Perfil::Coordenador), fn ($query) => $query->where('coordenador_id', $usuario->id))
                ->get(),
            'projetoEscolhido' => $request->integer('projeto') ?: null,
            'escola' => $usuario->demandante?->escolaInstituicao,
            'emNomeDaEscola' => $emNomeDaEscola,
            'escolas' => $emNomeDaEscola ? EscolaInstituicao::query()->where('ativo', true)->orderBy('nome')->get() : collect(),
            'demandantes' => $emNomeDaEscola ? $this->demandantesAtivos()->with('demandante.escolaInstituicao')->get() : collect(),
        ]);
    }

    /**
     * Fase 9: demandantes que a coordenação pode escolher ao abrir a demanda em nome da comunidade/instituição.
     *
     * @return Builder<Usuario>
     */
    private function demandantesAtivos(): Builder
    {
        return Usuario::query()
            ->with('demandante')
            ->where('perfil', Perfil::Demandante)
            ->where('status', StatusUsuario::Ativo)
            ->whereHas('demandante')
            ->orderBy('nome');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Solicitacao::class);

        $usuario = $request->user();

        // Cada arquivo aparece pelo nome nas mensagens de erro.
        $nomesDosArquivos = collect($request->file('anexos', []))
            ->mapWithKeys(fn ($arquivo, $indice) => ["anexos.{$indice}" => $arquivo instanceof UploadedFile
                ? "arquivo \"{$arquivo->getClientOriginalName()}\""
                : 'arquivo'])
            ->all();

        $emNomeDaEscola = Gate::allows('criarEmNomeDaEscola', Solicitacao::class);

        $dados = $request->validate([
            'projeto_base_id' => ['required', Rule::exists('projetos_base', 'id')->where('ativo', true)],
            // Fase 9: a coordenação pode informar a comunidade/instituição e, se ela tiver alguém cadastrado, o demandante que acompanha.
            'escola_instituicao_id' => $emNomeDaEscola
                ? ['nullable', Rule::exists('escolas_instituicoes', 'id')->where('ativo', true)]
                : ['prohibited'],
            'demandante_id' => $emNomeDaEscola
                ? ['nullable', Rule::exists('demandantes', 'usuario_id')->when(
                    $request->filled('escola_instituicao_id'),
                    fn ($regra) => $regra->where('escola_instituicao_id', $request->integer('escola_instituicao_id')),
                )]
                : ['prohibited'],
            'titulo' => ['required', 'string', 'max:100'],
            'descricao' => ['required', 'string', 'max:5000'],
            'justificativa' => ['required', 'string', 'max:5000'],
            'publico_afetado' => ['required', 'string', 'max:255'],
            'anexos' => ['nullable', 'array', 'max:'.Anexo::quantidadeMaxima()],
            'anexos.*' => ['file', 'mimes:'.implode(',', Anexo::EXTENSOES), 'max:'.Anexo::tamanhoMaximoMb() * 1024],
        ], [
            'projeto_base_id.exists' => 'Escolha um projeto de extensão ativo.',
            'escola_instituicao_id.exists' => 'Escolha uma comunidade ou instituição ativa.',
            'demandante_id.exists' => 'Escolha um demandante da comunidade/instituição selecionada, ou deixe sem demandante.',
            'anexos.max' => 'Envie no máximo :max arquivos.',
            'anexos.*.mimes' => 'O :attribute não é PDF, JPG ou PNG.',
            'anexos.*.max' => 'O :attribute tem mais de '.Anexo::tamanhoMaximoMb().' MB.',
            'anexos.*.uploaded' => 'O :attribute não pôde ser enviado. Verifique se ele tem até '.Anexo::tamanhoMaximoMb().' MB.',
        ], [
            ...$nomesDosArquivos,
            'projeto_base_id' => 'projeto de extensão',
            'escola_instituicao_id' => 'comunidade ou instituição',
            'demandante_id' => 'demandante',
            'titulo' => 'título',
            'descricao' => 'descrição',
            'publico_afetado' => 'público afetado',
        ]);

        // Fase 9: o coordenador só abre demandas nos projetos que coordena.
        if (Gate::denies('criarNoProjeto', [Solicitacao::class, ProjetoBase::findOrFail($dados['projeto_base_id'])])) {
            throw ValidationException::withMessages(['projeto_base_id' => 'Você só pode abrir demandas nos projetos que coordena.']);
        }

        if ($emNomeDaEscola) {
            $demandanteId = $dados['demandante_id'] ?? null;
            $demandante = $demandanteId === null ? null : $this->demandantesAtivos()->find($demandanteId);

            // Só demandante ativo acompanha a demanda.
            if ($demandanteId !== null && $demandante === null) {
                throw ValidationException::withMessages(['demandante_id' => 'Este demandante não está ativo. Escolha outro ou deixe sem demandante.']);
            }

            // Sem comunidade/instituição informada, a demanda fica com a comunidade/instituição do demandante escolhido (ou sem comunidade/instituição).
            $escolaId = $dados['escola_instituicao_id'] ?? $demandante?->demandante->escola_instituicao_id;
        } else {
            $escolaId = $usuario->demandante?->escola_instituicao_id;
            $demandanteId = $usuario->id;
            abort_if($escolaId === null, 403, 'Seu cadastro não tem comunidade ou instituição vinculada. Procure a coordenação do projeto.');
        }

        $arquivos = $request->file('anexos', []);
        $gravados = [];

        try {
            $solicitacao = DB::transaction(function () use ($dados, $usuario, $escolaId, $demandanteId, $arquivos, &$gravados): Solicitacao {
                $solicitacao = Solicitacao::create([
                    ...collect($dados)->except(['anexos', 'escola_instituicao_id', 'demandante_id'])->all(),
                    'demandante_id' => $demandanteId,
                    'cadastrada_por' => $usuario->id,
                    'escola_instituicao_id' => $escolaId,
                    'status' => StatusDemanda::Cadastrada,
                ]);

                foreach ($arquivos as $arquivo) {
                    $gravados[] = $caminho = $arquivo->store("solicitacoes/{$solicitacao->id}", 'local');
                    $this->registrarAnexo($solicitacao, $arquivo, $caminho, $usuario->id);
                }

                return $solicitacao;
            });
        } catch (Throwable $erro) {
            // A demanda não foi gravada: apaga os arquivos que já tinham sido salvos.
            Storage::disk('local')->delete($gravados);

            throw $erro;
        }

        return redirect()->route('demandas.show', $solicitacao)
            ->with('sucesso', $emNomeDaEscola
                ? 'Demanda cadastrada. Ela entra na fila como "Cadastrada" e segue a triagem normal.'
                : 'Demanda enviada. A coordenação do projeto vai analisar e você acompanha o andamento por aqui.');
    }

    public function show(Request $request, Solicitacao $solicitacao): View
    {
        Gate::authorize('view', $solicitacao);

        $solicitacao->load(['projetoBase.coordenador', 'demandante.demandante', 'cadastrador', 'escolaInstituicao', 'categoria', 'prioridade', 'triador', 'anexos', 'historicos.usuario', 'mensagens.autor', 'subprojeto' => fn ($query) => $query->withCount('membrosAtivos')]);

        $categorias = CategoriaDemanda::query()->ativos()->get();
        $prioridades = Prioridade::query()->ativos()->get();

        // Mantém nas listas os itens já escolhidos, mesmo que o admin os tenha inativado depois.
        if ($solicitacao->categoria && ! $solicitacao->categoria->ativo) {
            $categorias->push($solicitacao->categoria);
        }
        if ($solicitacao->prioridade && ! $solicitacao->prioridade->ativo) {
            $prioridades->push($solicitacao->prioridade);
        }

        return view('solicitacoes.show', [
            'solicitacao' => $solicitacao,
            'categorias' => $categorias,
            'prioridades' => $prioridades,
            'complexidades' => Complexidade::cases(),
            'statusDisponiveis' => app(SolicitacaoPolicy::class)->statusDisponiveis($request->user(), $solicitacao),
            // RF13: sugestão de status pelas entregas e as entregas que o usuário vê (o demandante, só depois da aprovação técnica).
            'statusSugerido' => $solicitacao->statusSugerido(),
            'entregas' => $solicitacao->subprojeto?->entregas()->get()->filter(fn (Entrega $entrega) => Gate::allows('view', $entrega))->values() ?? collect(),
        ]);
    }

    /**
     * RF07: categoria, prioridade e complexidade.
     */
    public function triar(Request $request, Solicitacao $solicitacao): RedirectResponse
    {
        Gate::authorize('triar', $solicitacao);

        $dados = $request->validate([
            // Os itens atuais continuam válidos mesmo que o admin os tenha inativado depois.
            'categoria_id' => ['required', Rule::exists('categorias_demanda', 'id')->where(
                fn ($query) => $query->where('ativo', true)->orWhere('id', $solicitacao->categoria_id)
            )],
            'prioridade_id' => ['required', Rule::exists('prioridades', 'id')->where(
                fn ($query) => $query->where('ativo', true)->orWhere('id', $solicitacao->prioridade_id)
            )],
            'complexidade' => ['required', Rule::enum(Complexidade::class)],
        ], [], [
            'categoria_id' => 'categoria',
            'prioridade_id' => 'prioridade',
        ]);

        // updateOrFail: a classificação e o registro no histórico são gravados juntos ou nenhum dos dois.
        $solicitacao->updateOrFail([
            ...$dados,
            'triado_por' => $request->user()->id,
            'triado_em' => now(),
        ]);

        return redirect()->route('demandas.show', $solicitacao)->with('sucesso', 'Classificação salva.');
    }

    /**
     * Mudança manual de status (decisão da Fase 0): o sistema nunca muda o status sozinho.
     */
    public function alterarStatus(Request $request, Solicitacao $solicitacao): RedirectResponse
    {
        Gate::authorize('alterarStatus', $solicitacao);

        $disponiveis = app(SolicitacaoPolicy::class)->statusDisponiveis($request->user(), $solicitacao);

        $dados = $request->validate([
            'status' => ['required', Rule::in(array_map(fn (StatusDemanda $status) => $status->value, $disponiveis))],
            'motivo_status' => ['nullable', 'string', 'max:1000'],
        ], [
            'status.in' => 'Este status não pode ser aplicado agora.',
        ]);

        $novoStatus = StatusDemanda::from($dados['status']);
        $motivo = trim((string) ($dados['motivo_status'] ?? ''));

        if ($novoStatus->exigeMotivo() && $motivo === '') {
            $acao = $novoStatus === StatusDemanda::Recusada ? 'recusar' : 'cancelar';

            return back()->withErrors(['motivo_status' => "Informe o motivo para {$acao} a demanda."])->withInput();
        }

        if ($novoStatus === StatusDemanda::Priorizada && ! $solicitacao->foiTriada()) {
            return back()->withErrors(['status' => 'Defina categoria, prioridade e complexidade antes de priorizar a demanda.']);
        }

        $solicitacao->updateOrFail([
            'status' => $novoStatus,
            'motivo_status' => $novoStatus->exigeMotivo() ? $motivo : null,
        ]);

        return redirect()->route('demandas.show', $solicitacao)
            ->with('sucesso', "Status alterado para \"{$novoStatus->rotulo()}\".");
    }

    /**
     * Download autorizado: o arquivo fica fora da pasta pública e só sai por aqui.
     */
    public function baixarAnexo(Solicitacao $solicitacao, Anexo $anexo): StreamedResponse
    {
        Gate::authorize('view', $solicitacao);
        abort_unless(Storage::disk('local')->exists($anexo->caminho), 404, 'Arquivo não encontrado.');

        return Storage::disk('local')->download($anexo->caminho, $anexo->nome_original);
    }

    private function registrarAnexo(Solicitacao $solicitacao, UploadedFile $arquivo, string $caminho, int $usuarioId): void
    {
        $solicitacao->anexos()->create([
            'nome_original' => mb_substr($arquivo->getClientOriginalName(), 0, 255),
            'caminho' => $caminho,
            'mime' => $arquivo->getMimeType() ?? 'application/octet-stream',
            'tamanho_bytes' => $arquivo->getSize(),
            'enviado_por' => $usuarioId,
        ]);
    }
}
