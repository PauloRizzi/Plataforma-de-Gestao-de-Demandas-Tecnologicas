<?php

namespace Tests\Feature;

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
use App\Notifications\Aviso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fase 9: ao criar ou editar o sub-projeto, a coordenação inclui na equipe, sem candidatura,
 * os estudantes vinculados ao Projeto Base.
 */
class EquipeManualTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $coordenador;

    private ProjetoBase $projeto;

    private Solicitacao $solicitacao;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->coordenador = Usuario::factory()->coordenador()->create();
        $this->projeto = ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id]);
        $this->solicitacao = Solicitacao::factory()->triada()->comStatus(StatusDemanda::Priorizada)->create(['projeto_base_id' => $this->projeto->id]);
    }

    /**
     * Estudante vinculado ao Projeto Base da demanda (só os vinculados entram pela coordenação).
     */
    private function vinculado(array $atributos = []): Usuario
    {
        $estudante = Usuario::factory()->estudante()->create($atributos);
        $this->projeto->estudantes()->attach($estudante);

        return $estudante;
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosValidos(array $alteracoes = []): array
    {
        return [
            'titulo' => 'Site da escola',
            'escopo' => 'Criar o site institucional com notícias e calendário.',
            'data_inicio' => '2026-11-01',
            'data_fim' => '2027-02-28',
            'carga_horaria_estimada' => 60,
            'vagas' => 2,
            'modalidade' => 'hibrido',
            ...$alteracoes,
        ];
    }

    private function papel(string $nome = PapelEquipe::PADRAO): PapelEquipe
    {
        return PapelEquipe::where('nome', $nome)->firstOrFail();
    }

    public function test_coordenador_inclui_estudantes_ao_criar_o_sub_projeto(): void
    {
        $ana = $this->vinculado(['nome' => 'Ana Extensionista']);
        $bruno = $this->vinculado();
        $this->vinculado(['status' => StatusUsuario::Pendente, 'nome' => 'Carla Pendente']);
        Usuario::factory()->estudante()->create(['nome' => 'Elisa Sem Vinculo']);
        $papel = PapelEquipe::where('ativo', true)->where('nome', '!=', PapelEquipe::PADRAO)->firstOrFail();

        $this->actingAs($this->coordenador)->get("/demandas/{$this->solicitacao->id}/subprojeto/criar")
            ->assertOk()
            ->assertSee('Incluir estudantes na equipe')
            ->assertSee('Ana Extensionista')
            ->assertDontSee('Carla Pendente')
            ->assertDontSee('Elisa Sem Vinculo');

        $this->actingAs($this->coordenador)
            ->post("/demandas/{$this->solicitacao->id}/subprojeto", $this->dadosValidos([
                'estudantes' => [$ana->id, $bruno->id],
                'papel_id' => $papel->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $subprojeto = $this->solicitacao->subprojeto()->sole();
        $this->assertEqualsCanonicalizing([$ana->id, $bruno->id], $subprojeto->membrosAtivos()->pluck('estudante_id')->all());
        $this->assertTrue($subprojeto->membrosAtivos()->get()->every(fn (MembroEquipe $membro) => $membro->papel_id === $papel->id && $membro->candidatura_id === null));
        $this->assertTrue($this->solicitacao->historicos()->where('descricao', "Ana Extensionista entrou na equipe como {$papel->nome}")->exists());

        Notification::assertSentTo($ana, Aviso::class, fn (Aviso $aviso) => $aviso->assunto === 'Você entrou na equipe: Site da escola');

        // Quem já está na equipe não pode se candidatar de novo.
        $this->assertSame('Você já faz parte desta equipe.', $subprojeto->impedimentoDeCandidatura($ana));
    }

    public function test_coordenador_inclui_estudante_ao_editar_e_a_candidatura_pendente_e_aprovada(): void
    {
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $this->solicitacao->id, 'vagas' => 2]);
        $membro = $this->vinculado(['nome' => 'Davi Membro']);
        MembroEquipe::create(['subprojeto_id' => $subprojeto->id, 'estudante_id' => $membro->id, 'papel_id' => $this->papel()->id]);
        $candidato = $this->vinculado();
        $candidatura = Candidatura::create(['subprojeto_id' => $subprojeto->id, 'estudante_id' => $candidato->id]);

        // Quem já está na equipe não aparece na lista para incluir.
        $this->actingAs($this->coordenador)->get("/subprojetos/{$subprojeto->id}/edit")
            ->assertOk()
            ->assertDontSee('Davi Membro');

        $this->actingAs($this->coordenador)->put("/subprojetos/{$subprojeto->id}", $this->dadosValidos([
            'estudantes' => [$candidato->id],
            'papel_id' => $this->papel()->id,
        ]))->assertSessionHasNoErrors();

        $candidatura->refresh();
        $this->assertSame(StatusCandidatura::Aprovada, $candidatura->status);
        $this->assertSame($this->coordenador->id, $candidatura->avaliado_por);
        $this->assertSame($candidatura->id, $subprojeto->membrosAtivos()->where('estudante_id', $candidato->id)->sole()->candidatura_id);

        // Quem tinha candidatura recebe só o aviso da aprovação.
        Notification::assertSentTo($candidato, Aviso::class, fn (Aviso $aviso) => $aviso->assunto === 'Candidatura aprovada: Site da escola');
        Notification::assertNotSentTo($candidato, Aviso::class, fn (Aviso $aviso) => str_starts_with($aviso->assunto, 'Você entrou na equipe'));
    }

    public function test_inclusao_respeita_as_vagas_o_cadastro_ativo_e_o_vinculo_com_o_projeto(): void
    {
        $subprojeto = Subprojeto::factory()->create(['solicitacao_id' => $this->solicitacao->id, 'vagas' => 2]);
        $membro = $this->vinculado();
        MembroEquipe::create(['subprojeto_id' => $subprojeto->id, 'estudante_id' => $membro->id, 'papel_id' => $this->papel()->id]);
        $um = $this->vinculado();
        $dois = $this->vinculado();
        $inativo = $this->vinculado(['status' => StatusUsuario::Inativo]);
        $semVinculo = Usuario::factory()->estudante()->create();
        $coordenador = Usuario::factory()->coordenador()->create();

        $this->actingAs($this->coordenador)->put("/subprojetos/{$subprojeto->id}", $this->dadosValidos([
            'estudantes' => [$um->id, $dois->id],
            'papel_id' => $this->papel()->id,
        ]))->assertSessionHasErrors(['estudantes' => 'A equipe ficaria com 3 estudantes, mas o sub-projeto tem 2 vaga(s). Aumente o número de vagas.']);

        // Nada foi gravado: nem os membros nem a alteração do sub-projeto.
        $this->assertSame(1, $subprojeto->membrosAtivos()->count());
        $this->assertSame(2, $subprojeto->fresh()->vagas);

        $this->actingAs($this->coordenador)->put("/subprojetos/{$subprojeto->id}", $this->dadosValidos([
            'estudantes' => [$inativo->id, $coordenador->id, $semVinculo->id],
            'papel_id' => $this->papel()->id,
        ]))->assertSessionHasErrors(['estudantes.0', 'estudantes.1', 'estudantes.2' => 'Escolha estudantes com cadastro ativo e vinculados ao projeto de extensão.']);

        $this->actingAs($this->coordenador)->put("/subprojetos/{$subprojeto->id}", $this->dadosValidos([
            'estudantes' => [$membro->id],
            'papel_id' => $this->papel()->id,
        ]))->assertSessionHasErrors('estudantes');

        // Aumentando as vagas na mesma edição, os dois entram.
        $this->actingAs($this->coordenador)->put("/subprojetos/{$subprojeto->id}", $this->dadosValidos([
            'vagas' => 3,
            'estudantes' => [$um->id, $dois->id],
            'papel_id' => $this->papel()->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(3, $subprojeto->membrosAtivos()->count());
    }

    public function test_papel_outros_esta_disponivel_e_a_carga_horaria_nao_tem_mais_o_aviso(): void
    {
        $this->actingAs($this->coordenador)->get("/demandas/{$this->solicitacao->id}/subprojeto/criar")
            ->assertOk()
            ->assertSee('<option value="'.$this->papel('Outros')->id.'"', false)
            ->assertDontSee('Estimada, por estudante.')
            ->assertSee('Nenhum estudante disponível');
    }
}
