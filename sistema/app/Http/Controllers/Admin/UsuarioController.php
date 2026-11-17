<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Perfil;
use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Enums\TipoEscola;
use App\Enums\TipoHistorico;
use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Models\EscolaInstituicao;
use App\Models\ProjetoBase;
use App\Models\Subprojeto;
use App\Models\Usuario;
use App\Notifications\CadastroAprovado;
use App\Notifications\CadastroRejeitado;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * RF04: o admin aprova os usuários, define o status e troca o perfil.
 */
class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'busca' => ['nullable', 'string', 'max:150'],
            'perfil' => ['nullable', Rule::enum(Perfil::class)],
            'status' => ['nullable', Rule::enum(StatusUsuario::class)],
        ]);

        $usuarios = Usuario::query()
            ->filtrar($filtros)
            ->orderByRaw('status = ? desc', [StatusUsuario::Pendente->value])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.usuarios.index', [
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'perfis' => Perfil::opcoes(),
            'statusOpcoes' => StatusUsuario::opcoes(),
            'totalPendentes' => Usuario::where('status', StatusUsuario::Pendente)->whereNotNull('email_verified_at')->count(),
        ]);
    }

    public function show(Usuario $usuario): View
    {
        $usuario->load(['estudante.curso', 'demandante.escolaInstituicao', 'coordenador', 'aprovador']);

        return view('admin.usuarios.show', [
            'usuario' => $usuario,
            'perfis' => Perfil::opcoes(),
            'statusOpcoes' => StatusUsuario::opcoes(),
            'cursos' => Curso::query()->ativos()->get(),
            'escolas' => EscolaInstituicao::query()->ativas()->get(),
            // "Editar dados": os ativos e o atual do usuário, mesmo que tenha sido inativado (ou ainda não conferido).
            'cursosDoEstudante' => Curso::query()->where(fn ($query) => $query->where('ativo', true)->orWhere('id', $usuario->estudante?->curso_id))->orderBy('nome')->get(),
            'escolasDoDemandante' => EscolaInstituicao::query()->where(fn ($query) => $query->where('ativo', true)->orWhere('id', $usuario->demandante?->escola_instituicao_id))->orderBy('nome')->get(),
            'tiposEscola' => TipoEscola::opcoes(),
            'proprioUsuario' => $usuario->is(auth()->user()),
            // E4: o que passa para a administração se o coordenador for inativado ou mudar de perfil.
            'coordenacaoAtual' => $usuario->temPerfil(Perfil::Coordenador) ? [
                'projetos' => ProjetoBase::query()->where('coordenador_id', $usuario->id)->count(),
                'subprojetos' => Subprojeto::query()->where('coordenador_id', $usuario->id)
                    ->whereHas('solicitacao', fn ($query) => $query->whereNotIn('status', array_map(fn (StatusDemanda $status) => $status->value, StatusDemanda::semEquipe())))
                    ->count(),
            ] : null,
        ]);
    }

    public function atualizarStatus(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->impedirAlteracaoPropria($usuario);

        $dados = $request->validate([
            'status' => ['required', Rule::enum(StatusUsuario::class)],
            'motivo_status' => ['nullable', 'string', 'max:1000'],
        ]);

        $novoStatus = StatusUsuario::from($dados['status']);
        $motivo = trim((string) ($dados['motivo_status'] ?? ''));

        if ($novoStatus->exigeMotivo() && $motivo === '') {
            $acao = $novoStatus === StatusUsuario::Rejeitado ? 'rejeitar' : 'inativar';

            return back()->withErrors(['motivo_status' => "Informe o motivo para {$acao} o usuário."])->withInput();
        }

        if ($novoStatus === StatusUsuario::Ativo && ! $usuario->hasVerifiedEmail()) {
            return back()->withErrors(['status' => 'Este usuário ainda não confirmou o e-mail e não pode ser aprovado.']);
        }

        $statusAnterior = $usuario->status;
        $primeiraAprovacao = $novoStatus === StatusUsuario::Ativo && $usuario->aprovado_em === null;

        $liberados = ['projetos' => 0, 'subprojetos' => 0];

        DB::transaction(function () use ($usuario, $novoStatus, $motivo, $primeiraAprovacao, $statusAnterior, &$liberados): void {
            if ($usuario->temPerfil(Perfil::Coordenador) && $statusAnterior === StatusUsuario::Ativo && $novoStatus !== StatusUsuario::Ativo) {
                $liberados = $this->liberarCoordenacao($usuario, 'deixou de estar ativo(a)');
            }

            $usuario->status = $novoStatus;
            $usuario->motivo_status = $novoStatus->exigeMotivo() ? $motivo : null;

            if ($primeiraAprovacao) {
                $usuario->aprovado_por = auth()->id();
                $usuario->aprovado_em = now();

                // A escola digitada no cadastro é conferida junto com a aprovação do demandante.
                $usuario->demandante?->escolaInstituicao()->where('ativo', false)->update(['ativo' => true]);
            }

            $usuario->save();
        });

        if ($primeiraAprovacao) {
            $usuario->notify(new CadastroAprovado);
        } elseif ($novoStatus === StatusUsuario::Rejeitado && $statusAnterior === StatusUsuario::Pendente) {
            $usuario->notify(new CadastroRejeitado($motivo));
        }

        return redirect()->route('admin.usuarios.show', $usuario)
            ->with('sucesso', "Status alterado de {$statusAnterior->rotulo()} para {$novoStatus->rotulo()}.".$this->mensagemDaCoordenacao($liberados));
    }

    public function atualizarPerfil(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->impedirAlteracaoPropria($usuario);

        $request->validate(['perfil' => ['required', Rule::enum(Perfil::class)]]);
        $novoPerfil = Perfil::from($request->input('perfil'));

        if ($novoPerfil === $usuario->perfil) {
            return back()->withErrors(['perfil' => 'O usuário já tem este perfil. Para corrigir os dados sem trocar o perfil, use "Editar dados".']);
        }

        $dados = $request->validate($this->regrasDoPerfil($novoPerfil, $usuario), [
            'telefone.regex' => 'Informe o telefone no formato (28) 99999-9999.',
        ], [
            'curso_id' => 'curso',
            'escola_instituicao_id' => 'escola ou instituição',
        ]);

        $perfilAnterior = $usuario->perfil;

        $liberados = ['projetos' => 0, 'subprojetos' => 0];

        DB::transaction(function () use ($usuario, $novoPerfil, $dados, $perfilAnterior, &$liberados): void {
            if ($perfilAnterior === Perfil::Coordenador) {
                $liberados = $this->liberarCoordenacao($usuario, "passou a ser {$novoPerfil->rotulo()}");
            }

            // Os dados do perfil anterior ficam guardados; só passam a valer os do perfil novo.
            match ($novoPerfil) {
                Perfil::Estudante => $usuario->estudante()->updateOrCreate([], [
                    'matricula' => $dados['matricula'],
                    'curso_id' => $dados['curso_id'],
                ]),
                Perfil::Demandante => $usuario->demandante()->updateOrCreate([], [
                    'escola_instituicao_id' => $dados['escola_instituicao_id'],
                    'cargo' => $dados['cargo'],
                ]),
                Perfil::Coordenador => $usuario->coordenador()->updateOrCreate([], [
                    'siape' => $dados['siape'] ?? null,
                    'departamento' => $dados['departamento'] ?? null,
                ]),
                Perfil::Admin => null,
            };

            if (isset($dados['telefone'])) {
                $usuario->telefone = $dados['telefone'];
            }

            $usuario->perfil = $novoPerfil;
            $usuario->save();
        });

        return redirect()->route('admin.usuarios.show', $usuario)
            ->with('sucesso', "Perfil alterado de {$perfilAnterior->rotulo()} para {$novoPerfil->rotulo()}.".$this->mensagemDaCoordenacao($liberados));
    }

    /**
     * E10 do teste completo: o admin corrige os dados do usuário (e os do perfil atual) sem trocar o perfil.
     * O e-mail não muda aqui, porque é o login. Mudar o demandante de escola também é feito aqui (E11).
     */
    public function atualizarDados(Request $request, Usuario $usuario): RedirectResponse
    {
        $usuario->load(['estudante', 'demandante', 'coordenador']);
        $perfil = $usuario->perfil;

        $regras = [
            'nome' => ['required', 'string', 'max:150'],
            'telefone' => [$perfil === Perfil::Demandante ? 'required' : 'nullable', 'regex:/^\(\d{2}\) \d{4,5}-\d{4}$/'],
            ...match ($perfil) {
                Perfil::Estudante => [
                    'matricula' => ['required', 'digits_between:6,20', Rule::unique('estudantes', 'matricula')->ignore($usuario->id, 'usuario_id')],
                    // O curso atual continua válido mesmo que tenha sido inativado depois.
                    'curso_id' => ['required', Rule::exists('cursos', 'id')->where(
                        fn ($query) => $query->where('ativo', true)->orWhere('id', $usuario->estudante?->curso_id)
                    )],
                ],
                Perfil::Demandante => [
                    'escola_instituicao_id' => ['required', Rule::exists('escolas_instituicoes', 'id')->where(
                        fn ($query) => $query->where('ativo', true)->orWhere('id', $usuario->demandante?->escola_instituicao_id)
                    )],
                    'cargo' => ['required', 'string', 'max:100'],
                ],
                Perfil::Coordenador => [
                    'siape' => ['nullable', 'digits_between:5,20', Rule::unique('coordenadores', 'siape')->ignore($usuario->id, 'usuario_id')],
                    'departamento' => ['nullable', 'string', 'max:150'],
                ],
                Perfil::Admin => [],
            },
        ];

        $dados = $request->validateWithBag('dados', $regras, [
            'telefone.regex' => 'Informe o telefone no formato (28) 99999-9999.',
        ], [
            'curso_id' => 'curso',
            'escola_instituicao_id' => 'escola ou instituição',
            'matricula' => 'matrícula',
        ]);

        DB::transaction(function () use ($usuario, $perfil, $dados): void {
            $usuario->update(['nome' => $dados['nome'], 'telefone' => $dados['telefone'] ?? null]);

            // As demandas já abertas guardam a escola da abertura; a troca vale para as próximas.
            match ($perfil) {
                Perfil::Estudante => $usuario->estudante()->updateOrCreate([], ['matricula' => $dados['matricula'], 'curso_id' => $dados['curso_id']]),
                Perfil::Demandante => $usuario->demandante()->updateOrCreate([], ['escola_instituicao_id' => $dados['escola_instituicao_id'], 'cargo' => $dados['cargo']]),
                Perfil::Coordenador => $usuario->coordenador()->updateOrCreate([], ['siape' => $dados['siape'] ?? null, 'departamento' => $dados['departamento'] ?? null]),
                Perfil::Admin => null,
            };
        });

        return redirect()->route('admin.usuarios.show', $usuario)->with('sucesso', 'Dados do usuário atualizados.');
    }

    /**
     * E11 do teste completo: só a escola nova, digitada no cadastro e ainda não conferida, é corrigida por aqui.
     * A escola já conferida vale para todos os que a usam; para mudar o demandante de escola, use "Editar dados".
     */
    public function atualizarEscola(Request $request, Usuario $usuario): RedirectResponse
    {
        $escola = $usuario->demandante?->escolaInstituicao;
        abort_unless($escola, 404);

        if ($escola->ativo) {
            return back()->withErrors(['escola' => 'Esta escola já foi conferida e vale para todos os que a usam. Para mudar o demandante de escola, use "Editar dados".']);
        }

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:200'],
            'tipo' => ['required', Rule::enum(TipoEscola::class)],
            'codigo_inep' => ['nullable', 'digits:8', Rule::unique('escolas_instituicoes', 'codigo_inep')->ignore($escola->id)],
            'cidade' => ['required', 'string', 'max:100'],
            'uf' => ['required', 'string', 'size:2', 'alpha'],
        ], [], ['codigo_inep' => 'código INEP']);

        $dados['uf'] = mb_strtoupper($dados['uf']);
        $escola->update($dados);

        return redirect()->route('admin.usuarios.show', $usuario)->with('sucesso', 'Dados da escola/instituição atualizados.');
    }

    /**
     * E4 do teste completo (escolha do autor): o coordenador inativado ou que mudou de perfil deixa os
     * Projetos Base e os sub-projetos em andamento para a administração (todos os admins), que depois
     * escolhe outro coordenador. Os sub-projetos de demandas encerradas continuam no nome dele.
     *
     * @return array{projetos: int, subprojetos: int}
     */
    private function liberarCoordenacao(Usuario $coordenador, string $motivo): array
    {
        $projetos = ProjetoBase::query()->where('coordenador_id', $coordenador->id)->update(['coordenador_id' => null]);

        $subprojetos = Subprojeto::query()
            ->with('solicitacao')
            ->where('coordenador_id', $coordenador->id)
            ->whereHas('solicitacao', fn ($query) => $query->whereNotIn('status', array_map(
                fn (StatusDemanda $status) => $status->value,
                StatusDemanda::semEquipe(),
            )))
            ->get();

        foreach ($subprojetos as $subprojeto) {
            $subprojeto->forceFill(['coordenador_id' => null])->saveQuietly();

            $subprojeto->solicitacao->historicos()->create([
                'usuario_id' => auth()->id(),
                'tipo' => TipoHistorico::Subprojeto,
                'descricao' => "A coordenação do sub-projeto passou para a administração ({$coordenador->nome} {$motivo})",
                'dados' => ['coordenador_anterior' => $coordenador->id],
            ]);
        }

        return ['projetos' => $projetos, 'subprojetos' => $subprojetos->count()];
    }

    private function mensagemDaCoordenacao(array $liberados): string
    {
        if ($liberados['projetos'] === 0 && $liberados['subprojetos'] === 0) {
            return '';
        }

        return " {$liberados['projetos']} Projeto(s) Base e {$liberados['subprojetos']} sub-projeto(s) em andamento passaram para a administração: escolha outro coordenador na edição de cada Projeto Base (ele passa a gerenciar também os sub-projetos).";
    }

    /**
     * O admin não altera o próprio status ou perfil, para não perder o acesso ao painel.
     */
    private function impedirAlteracaoPropria(Usuario $usuario): void
    {
        abort_if($usuario->is(auth()->user()), 403, 'Você não pode alterar o seu próprio status ou perfil.');
    }

    /**
     * Campos obrigatórios para o usuário passar a ter o perfil novo.
     *
     * @return array<string, array<mixed>>
     */
    private function regrasDoPerfil(Perfil $perfil, Usuario $usuario): array
    {
        return match ($perfil) {
            Perfil::Estudante => [
                'matricula' => ['required', 'digits_between:6,20', Rule::unique('estudantes', 'matricula')->ignore($usuario->id, 'usuario_id')],
                'curso_id' => ['required', Rule::exists('cursos', 'id')->where('ativo', true)],
            ],
            Perfil::Demandante => [
                'escola_instituicao_id' => ['required', Rule::exists('escolas_instituicoes', 'id')->where('ativo', true)],
                'cargo' => ['required', 'string', 'max:100'],
                'telefone' => ['required', 'regex:/^\(\d{2}\) \d{4,5}-\d{4}$/'],
            ],
            Perfil::Coordenador => [
                'siape' => ['nullable', 'digits_between:5,20', Rule::unique('coordenadores', 'siape')->ignore($usuario->id, 'usuario_id')],
                'departamento' => ['nullable', 'string', 'max:150'],
            ],
            Perfil::Admin => [],
        };
    }
}
