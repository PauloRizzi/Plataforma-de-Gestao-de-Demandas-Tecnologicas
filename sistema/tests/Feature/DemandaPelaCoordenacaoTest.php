<?php

namespace Tests\Feature;

use App\Enums\SituacaoEntrega;
use App\Enums\StatusDemanda;
use App\Enums\StatusUsuario;
use App\Models\Entrega;
use App\Models\EscolaInstituicao;
use App\Models\MembroEquipe;
use App\Models\PapelEquipe;
use App\Models\ProjetoBase;
use App\Models\Solicitacao;
use App\Models\Subprojeto;
use App\Models\Usuario;
use App\Notifications\Aviso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 9: o coordenador abre demandas nos projetos que coordena, e o admin em qualquer projeto.
 * A demanda fica em nome de uma escola e, se ela tiver alguém cadastrado, de um demandante.
 */
class DemandaPelaCoordenacaoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $coordenador;

    private ProjetoBase $projeto;

    private Usuario $demandante;

    private EscolaInstituicao $escola;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->coordenador = Usuario::factory()->coordenador()->create();
        $this->projeto = ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id]);
        $this->demandante = Usuario::factory()->demandante()->create();
        $this->escola = $this->demandante->demandante->escolaInstituicao;
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosValidos(array $extra = []): array
    {
        return array_merge([
            'projeto_base_id' => $this->projeto->id,
            'escola_instituicao_id' => $this->escola->id,
            'demandante_id' => $this->demandante->id,
            'titulo' => 'Rede Wi-Fi da biblioteca',
            'descricao' => 'A escola pediu por ofício a instalação de Wi-Fi na biblioteca.',
            'justificativa' => 'Os alunos não conseguem pesquisar na biblioteca.',
            'publico_afetado' => '250 alunos',
        ], $extra);
    }

    public function test_coordenador_abre_demanda_em_nome_do_demandante_da_escola(): void
    {
        $this->actingAs($this->coordenador)->get('/demandas/create')
            ->assertOk()
            ->assertSee($this->projeto->titulo)
            ->assertSee($this->escola->nome)
            ->assertSee($this->demandante->nome);

        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['anexos' => [UploadedFile::fake()->create('oficio.pdf', 100, 'application/pdf')]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $solicitacao = Solicitacao::sole();
        $this->assertSame($this->demandante->id, $solicitacao->demandante_id);
        $this->assertSame($this->coordenador->id, $solicitacao->cadastrada_por);
        $this->assertSame($this->escola->id, $solicitacao->escola_instituicao_id);
        $this->assertSame(StatusDemanda::Cadastrada, $solicitacao->status);
        $this->assertCount(1, $solicitacao->anexos);
        $this->assertTrue($solicitacao->cadastradaPelaCoordenacao());

        $historico = $solicitacao->historicos()->sole();
        $this->assertSame("Demanda aberta pela coordenação em nome de {$this->demandante->nome}", $historico->descricao);
        $this->assertSame($this->coordenador->id, $historico->usuario_id);

        // O demandante é avisado e acompanha a demanda em "Minhas demandas"; o coordenador não recebe aviso da própria ação.
        Notification::assertSentTo($this->demandante, Aviso::class, fn (Aviso $aviso) => $aviso->assunto === 'Demanda cadastrada em seu nome: Rede Wi-Fi da biblioteca');
        Notification::assertNotSentTo($this->coordenador, Aviso::class);

        $this->actingAs($this->demandante)->get('/demandas')->assertSee('Rede Wi-Fi da biblioteca');
        $this->actingAs($this->demandante)->get("/demandas/{$solicitacao->id}")
            ->assertOk()
            ->assertSee('Cadastrada por')
            ->assertSee($this->coordenador->nome);
    }

    public function test_coordenador_so_abre_demanda_nos_projetos_que_coordena(): void
    {
        $outroProjeto = ProjetoBase::factory()->create(['titulo' => 'Projeto de Outro Coordenador']);

        $this->actingAs($this->coordenador)->get('/demandas/create')
            ->assertSee($this->projeto->titulo)
            ->assertDontSee('Projeto de Outro Coordenador');

        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['projeto_base_id' => $outroProjeto->id]))
            ->assertSessionHasErrors('projeto_base_id');

        $this->assertDatabaseCount('solicitacoes', 0);

        // O botão "Nova demanda neste projeto" só aparece no projeto que ele coordena.
        $this->actingAs($this->coordenador)->get("/projetos-base/{$this->projeto->id}")->assertSee('Nova demanda neste projeto');
        $this->actingAs($this->coordenador)->get("/projetos-base/{$outroProjeto->id}")->assertDontSee('Nova demanda neste projeto');
    }

    public function test_admin_abre_demanda_em_qualquer_projeto_sem_demandante(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $escolaSemConta = EscolaInstituicao::factory()->create(['nome' => 'EMEF Córrego Fundo']);

        $this->actingAs($admin)->get('/demandas/create')->assertOk()->assertSee('EMEF Córrego Fundo');
        $this->actingAs($admin)->get("/projetos-base/{$this->projeto->id}")->assertSee('Nova demanda neste projeto');

        $this->actingAs($admin)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => $escolaSemConta->id, 'demandante_id' => '']))
            ->assertSessionHasNoErrors();

        $solicitacao = Solicitacao::sole();
        $this->assertNull($solicitacao->demandante_id);
        $this->assertSame($admin->id, $solicitacao->cadastrada_por);
        $this->assertSame($escolaSemConta->id, $solicitacao->escola_instituicao_id);
        $this->assertSame('Demanda aberta pela coordenação para EMEF Córrego Fundo (sem demandante no sistema)', $solicitacao->historicos()->sole()->descricao);

        // A coordenação do projeto é avisada da demanda nova.
        Notification::assertSentTo($this->coordenador, Aviso::class, fn (Aviso $aviso) => $aviso->assunto === 'Nova demanda: Rede Wi-Fi da biblioteca');

        $this->actingAs($this->coordenador)->get("/demandas/{$solicitacao->id}")
            ->assertOk()
            ->assertSee('Sem demandante no sistema');
    }

    public function test_demandante_precisa_ser_da_escola_escolhida_e_estar_ativo(): void
    {
        $outraEscola = EscolaInstituicao::factory()->create();
        $inativo = Usuario::factory()->demandante()->create(['status' => StatusUsuario::Inativo]);
        $escolaInativa = EscolaInstituicao::factory()->inativa()->create();

        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => $outraEscola->id]))
            ->assertSessionHasErrors('demandante_id');

        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => $inativo->demandante->escola_instituicao_id, 'demandante_id' => $inativo->id]))
            ->assertSessionHasErrors('demandante_id');

        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => $escolaInativa->id, 'demandante_id' => '']))
            ->assertSessionHasErrors('escola_instituicao_id');

        $this->assertDatabaseCount('solicitacoes', 0);
    }

    public function test_escola_e_opcional_para_a_coordenacao(): void
    {
        // Sem escola, mas com demandante: a demanda fica com a escola do demandante.
        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => '', 'titulo' => 'Com demandante']))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->escola->id, Solicitacao::where('titulo', 'Com demandante')->sole()->escola_instituicao_id);

        // Sem escola e sem demandante.
        $this->actingAs($this->coordenador)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => '', 'demandante_id' => '', 'titulo' => 'Sem escola']))
            ->assertSessionHasNoErrors();

        $solicitacao = Solicitacao::where('titulo', 'Sem escola')->sole();
        $this->assertNull($solicitacao->escola_instituicao_id);
        $this->assertNull($solicitacao->demandante_id);
        $this->assertSame('Demanda aberta pela coordenação (sem escola e sem demandante no sistema)', $solicitacao->historicos()->sole()->descricao);

        $this->actingAs($this->coordenador)->get("/demandas/{$solicitacao->id}")->assertOk()->assertSee('Não informada');
        $this->actingAs($this->coordenador)->get('/demandas')->assertOk()->assertSee('Sem escola');
    }

    public function test_demandante_continua_abrindo_so_para_a_propria_escola(): void
    {
        $outro = Usuario::factory()->demandante()->create();

        $this->actingAs($this->demandante)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => $outro->demandante->escola_instituicao_id, 'demandante_id' => $outro->id]))
            ->assertSessionHasErrors(['escola_instituicao_id', 'demandante_id']);

        $this->actingAs($this->demandante)
            ->post('/demandas', $this->dadosValidos(['escola_instituicao_id' => null, 'demandante_id' => null]))
            ->assertSessionHasNoErrors();

        $solicitacao = Solicitacao::sole();
        $this->assertSame($this->demandante->id, $solicitacao->demandante_id);
        $this->assertSame($this->demandante->id, $solicitacao->cadastrada_por);
        $this->assertFalse($solicitacao->cadastradaPelaCoordenacao());
        $this->assertSame('Demanda aberta', $solicitacao->historicos()->sole()->descricao);
    }

    public function test_demanda_sem_demandante_segue_o_fluxo_com_o_aceite_pela_coordenacao(): void
    {
        $solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::EmDesenvolvimento)->create([
            'projeto_base_id' => $this->projeto->id,
            'demandante_id' => null,
            'cadastrada_por' => $this->coordenador->id,
            'escola_instituicao_id' => $this->escola->id,
        ]);
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $solicitacao->id]);
        $estudante = Usuario::factory()->estudante()->create();
        MembroEquipe::create([
            'subprojeto_id' => $subprojeto->id,
            'estudante_id' => $estudante->id,
            'papel_id' => PapelEquipe::where('nome', PapelEquipe::PADRAO)->firstOrFail()->id,
        ]);
        $entrega = Entrega::factory()->comSituacao(SituacaoEntrega::AguardandoAceite)->create([
            'subprojeto_id' => $subprojeto->id,
            'enviado_por' => $estudante->id,
        ]);

        // Telas da demanda e da entrega abrem sem demandante; o chat e a mudança de status funcionam.
        $this->actingAs($this->coordenador)->get("/demandas/{$solicitacao->id}")->assertOk();
        $this->actingAs($estudante)->get("/demandas/{$solicitacao->id}")->assertOk();
        $this->actingAs($estudante)->post("/demandas/{$solicitacao->id}/mensagens", ['texto' => 'Instalação marcada para terça.'])->assertSessionHasNoErrors();

        $this->actingAs($this->coordenador)->get("/subprojetos/{$subprojeto->id}/entregas/{$entrega->id}")
            ->assertOk()
            ->assertSee('Esta demanda não tem demandante no sistema');

        $this->actingAs($this->coordenador)
            ->post("/subprojetos/{$subprojeto->id}/entregas/{$entrega->id}/aceite", ['decisao' => 'aceita'])
            ->assertSessionHas('sucesso', 'Aceite registrado em nome da escola. Se for o caso, mude a demanda para "Concluída".');

        $this->assertSame(SituacaoEntrega::Aceita, $entrega->fresh()->situacao);
        $this->assertStringEndsWith('(registrado pela coordenação em nome da escola)', $solicitacao->historicos()->reorder()->latest('id')->firstOrFail()->descricao);

        $this->actingAs($this->coordenador)
            ->patch("/demandas/{$solicitacao->id}/status", ['status' => StatusDemanda::EmValidacao->value])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatusDemanda::EmValidacao, $solicitacao->fresh()->status);
    }
}
