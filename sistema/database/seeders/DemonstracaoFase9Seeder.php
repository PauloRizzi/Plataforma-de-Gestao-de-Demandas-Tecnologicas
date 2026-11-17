<?php

namespace Database\Seeders;

use App\Enums\Complexidade;
use App\Enums\Modalidade;
use App\Enums\Perfil;
use App\Enums\StatusDemanda;
use App\Enums\TipoEscola;
use App\Models\EscolaInstituicao;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Subprojeto;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Fase 9: complemento dos dados de demonstração com as novidades da fase (vínculo dos estudantes
 * aos projetos, demandas abertas pela coordenação, inclusão manual na equipe, papel "Outros" e
 * término prorrogado). Só acrescenta dados: não apaga nem altera o que já existe no banco, e pode
 * rodar de novo sem duplicar. Uso, depois do DemonstracaoSeeder:
 * `php artisan db:seed --class=DemonstracaoFase9Seeder`.
 */
class DemonstracaoFase9Seeder extends DemonstracaoSeeder
{
    /** Conta criada por este complemento: se existir, o complemento já foi carregado. */
    private const MARCA = 'gabriela';

    /**
     * Estudantes vinculados a cada projeto (os da Fase 8 e os novos deste complemento).
     *
     * @var array<string, list<string>>
     */
    private const VINCULOS = [
        'robotica' => ['ana', 'camila', 'diego', 'eduarda', 'felipe', 'gabriela', 'hugo', 'isabela'],
        'conecta' => ['ana', 'diego', 'felipe', 'lucas', 'hugo'],
        'digital' => ['ana', 'bruno', 'camila', 'eduarda', 'marina', 'isabela'],
        'formacao' => ['bruno', 'eduarda', 'marina'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Os dados de demonstração não podem ser carregados em produção.');

            return;
        }

        $ana = Usuario::where('email', $this->email('ana'))->first();

        if ($ana === null) {
            $this->command?->warn('Carregue antes os dados de demonstração: php artisan db:seed --class=DemonstracaoSeeder');

            return;
        }

        $this->carregarExistentes();

        // As contas novas usam a mesma senha das contas de demonstração que já existem.
        $this->senha = $ana->getAuthPassword();

        Notification::fake();

        try {
            if (Usuario::where('email', $this->email(self::MARCA))->exists()) {
                $this->vincular();
                $this->command?->warn('O complemento da Fase 9 já foi carregado neste banco. Só os vínculos foram conferidos.');

                return;
            }

            $this->criarEstudantesEEscola();
            $this->vincular();
            $this->criarDemandasDaFase9();
        } finally {
            Carbon::setTestNow();
            Auth::forgetUser();
        }

        $this->command?->info('Complemento da Fase 9 carregado. Nada foi apagado. Contas novas (mesma senha das outras contas de demonstração):');
        $this->command?->table(['Perfil', 'Nome', 'E-mail'], Usuario::query()
            ->whereIn('email', array_map(fn (string $chave) => $this->email($chave), ['gabriela', 'hugo', 'isabela', 'lucas', 'marina']))
            ->orderBy('nome')
            ->get()
            ->map(fn (Usuario $usuario) => [$usuario->perfil->rotulo(), $usuario->nome, $usuario->email])
            ->all());
    }

    /**
     * Pessoas, escolas e projetos criados pelo DemonstracaoSeeder, procurados pelo e-mail e pelo nome.
     */
    private function carregarExistentes(): void
    {
        $this->admin = Usuario::where('perfil', Perfil::Admin)->orderBy('id')->firstOrFail();

        $contas = [
            'mariana' => 'mariana.lopes', 'ricardo' => 'ricardo.alves',
            'sonia' => 'sonia.ribeiro', 'joao' => 'joao.moreira', 'regina' => 'regina.coutinho', 'lucia' => 'lucia.barros',
            'ana' => 'ana', 'bruno' => 'bruno', 'camila' => 'camila', 'diego' => 'diego', 'eduarda' => 'eduarda', 'felipe' => 'felipe',
            'gabriela' => 'gabriela', 'hugo' => 'hugo', 'isabela' => 'isabela', 'lucas' => 'lucas', 'marina' => 'marina',
        ];

        foreach ($contas as $chave => $usuario) {
            if ($conta = Usuario::where('email', $this->email($usuario))->first()) {
                $this->pessoas[$chave] = $conta;
            }
        }

        $this->escolas = EscolaInstituicao::query()
            ->whereIn('nome', ['EEEFM Vale das Palmeiras', 'EMEF Córrego Alegre', 'EMEIEF Pedra Azul', 'Associação Comunitária Vila Nova', 'EMEF Boa Esperança'])
            ->get()
            ->keyBy(fn (EscolaInstituicao $escola) => match ($escola->nome) {
                'EEEFM Vale das Palmeiras' => 'palmeiras',
                'EMEF Córrego Alegre' => 'corrego',
                'EMEIEF Pedra Azul' => 'pedra',
                'Associação Comunitária Vila Nova' => 'vilanova',
                'EMEF Boa Esperança' => 'boaesperanca',
            })
            ->all();

        $titulos = [
            'robotica' => 'Robótica Educacional nas Escolas',
            'conecta' => 'Conecta Escola: redes e infraestrutura',
            'digital' => 'Presença Digital das Escolas',
            'formacao' => 'Formação Tecnológica para Educadores',
        ];

        foreach ($titulos as $chave => $titulo) {
            $this->projetos[$chave] = ProjetoBase::where('titulo', $titulo)->firstOrFail();
        }
    }

    /**
     * Cinco estudantes novos e uma escola que ainda não tem ninguém cadastrado no sistema.
     */
    private function criarEstudantesEEscola(): void
    {
        $this->quando(150);

        $novos = [
            'gabriela' => ['Gabriela Nunes', 'Sistemas de Informação'],
            'hugo' => ['Hugo Carvalho', 'Ciência da Computação'],
            'isabela' => ['Isabela Moura', 'Matemática'],
            'lucas' => ['Lucas Prado', 'Ciência da Computação'],
            'marina' => ['Marina Tavares', 'Sistemas de Informação'],
        ];

        foreach ($novos as $chave => [$nome, $curso]) {
            $this->pessoas[$chave] = $this->estudante($nome, $chave, $curso);
        }

        $this->escolas['boaesperanca'] = $this->escola('EMEF Boa Esperança', TipoEscola::EscolaPublica, 'Dores do Rio Preto');
    }

    /**
     * Acrescenta os vínculos que faltam, sem desfazer nenhum que já exista.
     */
    private function vincular(): void
    {
        foreach (self::VINCULOS as $projeto => $estudantes) {
            $ids = collect($estudantes)
                ->filter(fn (string $estudante) => isset($this->pessoas[$estudante]))
                ->map(fn (string $estudante) => $this->pessoas[$estudante]->id)
                ->all();

            $this->projetos[$projeto]->estudantes()->syncWithoutDetaching($ids);
        }
    }

    private function criarDemandasDaFase9(): void
    {
        // Coordenação abre em nome de um demandante; sub-projeto na vitrine com inclusão manual e papel "Outros".
        $scratch = $this->abrirPelaCoordenacao(30, 'mariana', 'robotica', 'pedra', 'regina', 'Clube de programação com Scratch no contraturno',
            'A escola pediu, em reunião, um clube semanal de programação com Scratch para os alunos do 6º e do 7º ano.',
            'Os alunos ficam na escola no contraturno sem atividade orientada.', '40 alunos do 6º e do 7º ano');
        $this->status($scratch, 28, StatusDemanda::EmAnalise);
        $this->triar($scratch, 28, 'Capacitação e formação', 'Média', Complexidade::Baixa);
        $this->status($scratch, 26, StatusDemanda::Priorizada);
        $clube = $this->subprojeto($scratch, 25, ['vagas' => 4, 'carga_horaria_estimada' => 40, 'modalidade' => Modalidade::Presencial,
            'data_inicio' => now()->addDays(15), 'data_fim' => now()->addDays(110)],
            'Planejar e conduzir encontros semanais do clube de programação com Scratch, com um projeto final por turma.');
        $this->incluir($clube, 24, 'gabriela', 'Responsável Técnico');
        $this->incluir($clube, 24, 'isabela', 'Outros');
        $this->candidatar($clube, 6, ['hugo']);

        // Admin abre para uma escola sem ninguém cadastrado: a demanda fica sem demandante.
        $cabeamento = $this->abrirPelaCoordenacao(12, 'admin', 'conecta', 'boaesperanca', null, 'Cabeamento da sala de informática',
            'A diretora ligou pedindo ajuda para passar cabos de rede na sala de informática nova, com 10 pontos.',
            'A sala foi montada, mas os computadores ainda não têm acesso à internet.', '300 alunos do ensino fundamental');
        $this->status($cabeamento, 11, StatusDemanda::EmAnalise);
        $this->triar($cabeamento, 11, 'Redes e conectividade', 'Alta', Complexidade::Media);
        $this->conversar($cabeamento, [
            [10, 'ricardo', 'Liguei para a escola e combinei uma visita técnica na próxima semana para medir a sala.'],
        ]);

        // Coordenação registra um pedido sem escola e sem demandante (ex.: veio da Secretaria de Educação).
        $this->abrirPelaCoordenacao(4, 'ricardo', 'formacao', null, null, 'Curso de planilhas para as secretarias escolares',
            'A Secretaria Municipal de Educação pediu um curso curto de planilhas para as secretárias das escolas da rede.',
            'As escolas ainda controlam matrículas e frequência em papel.', 'Cerca de 25 secretárias escolares');

        // Sub-projeto em desenvolvimento, com inclusão manual, candidatura aprovada e término prorrogado.
        $rede = $this->abrirPelaCoordenacao(70, 'ricardo', 'conecta', 'corrego', 'joao', 'Rede cabeada para a biblioteca',
            'A biblioteca precisa de 6 pontos de rede cabeada para os computadores de pesquisa.',
            'O Wi-Fi não é suficiente para os computadores fixos da biblioteca.', 'Toda a escola (cerca de 350 alunos)');
        $this->status($rede, 68, StatusDemanda::EmAnalise);
        $this->triar($rede, 68, 'Redes e conectividade', 'Média', Complexidade::Media);
        $this->status($rede, 66, StatusDemanda::Priorizada);
        $cabos = $this->subprojeto($rede, 65, ['vagas' => 3, 'carga_horaria_estimada' => 24, 'modalidade' => Modalidade::Presencial,
            'data_inicio' => now()->subDays(60), 'data_fim' => now()->addDays(5)],
            'Passar os cabos, montar os 6 pontos de rede e testar a conexão dos computadores da biblioteca.');
        $this->incluir($cabos, 64, 'lucas', 'Membro Desenvolvedor');
        $this->equipe($cabos, 62, ['hugo' => 'Apoio de Campo']);
        $this->status($rede, 60, StatusDemanda::EmDesenvolvimento);
        $this->quando(3, $this->pessoas['ricardo']);
        $cabos->update(['data_fim' => now()->addDays(35)->toDateString(), 'candidaturas_abertas' => false]);

        // Outro sub-projeto aberto na vitrine, no projeto "Presença Digital".
        $cardapio = $this->abrir(16, 'joao', 'digital', 'Cardápio da merenda no site da escola',
            'Gostaríamos de publicar o cardápio semanal da merenda para as famílias acompanharem.',
            'As famílias perguntam o cardápio todos os dias na secretaria.', 'Famílias dos 350 alunos');
        $this->status($cardapio, 15, StatusDemanda::EmAnalise);
        $this->triar($cardapio, 15, 'Site e presença digital', 'Baixa', Complexidade::Baixa);
        $this->status($cardapio, 13, StatusDemanda::Priorizada);
        $publicacao = $this->subprojeto($cardapio, 12, ['vagas' => 2, 'carga_horaria_estimada' => 16, 'modalidade' => Modalidade::Remoto,
            'data_inicio' => now()->addDays(10), 'data_fim' => now()->addDays(50)],
            'Criar uma página simples para a escola publicar o cardápio da semana, com instruções para a secretaria atualizar.');
        $this->incluir($publicacao, 11, 'marina', 'Membro Desenvolvedor');
    }

    /**
     * Demanda aberta pela coordenação ou pelo admin (Fase 9), com escola e demandante opcionais.
     */
    private function abrirPelaCoordenacao(int $diasAtras, string $autor, string $projeto, ?string $escola, ?string $demandante, string $titulo, string $descricao, string $justificativa, string $publico): Solicitacao
    {
        $cadastrador = $autor === 'admin' ? $this->admin : $this->pessoas[$autor];

        $this->ultimoPasso = null;
        $this->quando($diasAtras, $cadastrador);

        return Solicitacao::create([
            'projeto_base_id' => $this->projetos[$projeto]->id,
            'demandante_id' => $demandante ? $this->pessoas[$demandante]->id : null,
            'cadastrada_por' => $cadastrador->id,
            'escola_instituicao_id' => $escola ? $this->escolas[$escola]->id : null,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'justificativa' => $justificativa,
            'publico_afetado' => $publico,
            'status' => StatusDemanda::Cadastrada,
        ]);
    }

    /**
     * Inclusão direta pela coordenação, sem candidatura (Fase 9).
     */
    private function incluir(Subprojeto $subprojeto, int $diasAtras, string $estudante, string $papel): void
    {
        $this->quando($diasAtras, $this->coordenacaoDe($subprojeto->solicitacao));

        $subprojeto->solicitacao->projetoBase->estudantes()->syncWithoutDetaching([$this->pessoas[$estudante]->id]);

        MembroEquipe::create([
            'subprojeto_id' => $subprojeto->id,
            'estudante_id' => $this->pessoas[$estudante]->id,
            'papel_id' => PapelEquipe::where('nome', $papel)->firstOrFail()->id,
        ]);
    }
}
