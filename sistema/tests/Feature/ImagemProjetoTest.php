<?php

namespace Tests\Feature;

use App\Models\ProjetoBase;
use App\Models\Tipologia;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 9: imagem do Projeto Base, com fundo padrão quando não há imagem.
 */
class ImagemProjetoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $coordenador;

    private Tipologia $tipologia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->coordenador = Usuario::factory()->coordenador()->create();
        $this->tipologia = Tipologia::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosValidos(array $extra = []): array
    {
        return array_merge([
            'titulo' => 'Robótica nas Escolas',
            'descricao' => 'Oficinas de robótica para estudantes do ensino fundamental.',
            'data_inicio' => '2026-11-01',
            'tipologia_id' => $this->tipologia->id,
            'publico' => '1',
            'ativo' => '1',
        ], $extra);
    }

    private function projetoDoCoordenador(array $extra = []): ProjetoBase
    {
        return ProjetoBase::factory()->create(['coordenador_id' => $this->coordenador->id, ...$extra]);
    }

    public function test_cadastro_com_imagem_grava_jpeg_reduzido_fora_da_pasta_publica(): void
    {
        $this->actingAs($this->coordenador)
            ->post('/projetos-base', $this->dadosValidos(['imagem' => UploadedFile::fake()->image('capa.png', 3200, 1200)]))
            ->assertSessionHasNoErrors();

        $projeto = ProjetoBase::firstOrFail();
        $this->assertNotNull($projeto->imagem);
        $this->assertStringStartsWith("projetos/{$projeto->id}/", $projeto->imagem);
        Storage::disk('local')->assertExists($projeto->imagem);

        [$largura, $altura, $tipo] = getimagesizefromstring(Storage::disk('local')->get($projeto->imagem));
        $this->assertSame([1600, 600, IMAGETYPE_JPEG], [$largura, $altura, $tipo]);

        $this->actingAs($this->coordenador)->get("/projetos-base/{$projeto->id}")
            ->assertOk()
            ->assertSee($projeto->urlImagem(), false);
    }

    public function test_projeto_sem_imagem_mostra_o_fundo_padrao_em_todas_as_telas(): void
    {
        $projeto = $this->projetoDoCoordenador();
        $padrao = asset('images/projeto-padrao.svg');

        $this->assertNull($projeto->imagem);
        $this->assertSame($padrao, $projeto->urlImagem());
        $this->assertFileExists(public_path('images/projeto-padrao.svg'));

        $this->get('/projetos')->assertSee($padrao, false);
        $this->get("/projetos/{$projeto->id}")->assertSee($padrao, false);

        $this->actingAs($this->coordenador)->get('/projetos-base')->assertSee($padrao, false);
        $this->actingAs($this->coordenador)->get("/projetos-base/{$projeto->id}")->assertSee($padrao, false);
        $this->actingAs($this->coordenador)->get('/projetos-base/create')->assertSee($padrao, false);
    }

    public function test_trocar_a_imagem_apaga_a_anterior_e_remover_volta_ao_padrao(): void
    {
        $projeto = $this->projetoDoCoordenador();
        $dados = $this->dadosValidos(['titulo' => $projeto->titulo]);

        $this->actingAs($this->coordenador)->put("/projetos-base/{$projeto->id}", [...$dados, 'imagem' => UploadedFile::fake()->image('a.jpg', 800, 450)]);
        $primeira = $projeto->refresh()->imagem;

        $this->actingAs($this->coordenador)->put("/projetos-base/{$projeto->id}", [...$dados, 'imagem' => UploadedFile::fake()->image('b.jpg', 800, 450)]);
        $segunda = $projeto->refresh()->imagem;

        $this->assertNotSame($primeira, $segunda);
        Storage::disk('local')->assertMissing($primeira);
        Storage::disk('local')->assertExists($segunda);

        // Salvar sem mandar imagem mantém a atual.
        $this->actingAs($this->coordenador)->put("/projetos-base/{$projeto->id}", $dados);
        $this->assertSame($segunda, $projeto->refresh()->imagem);

        $this->actingAs($this->coordenador)->put("/projetos-base/{$projeto->id}", [...$dados, 'remover_imagem' => '1']);
        $this->assertNull($projeto->refresh()->imagem);
        Storage::disk('local')->assertMissing($segunda);
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado(): void
    {
        $falso = UploadedFile::fake()->createWithContent('capa.png', '<?php echo "invasao"; ?>');
        $pdf = UploadedFile::fake()->create('capa.pdf', 100, 'application/pdf');
        $pequena = UploadedFile::fake()->image('icone.png', 50, 50);
        $grande = UploadedFile::fake()->image('enorme.jpg', 400, 400)->size(6 * 1024);

        foreach ([$falso, $pdf, $pequena, $grande] as $arquivo) {
            $this->actingAs($this->coordenador)
                ->post('/projetos-base', $this->dadosValidos(['imagem' => $arquivo]))
                ->assertSessionHasErrors('imagem');
        }

        $this->assertDatabaseCount('projetos_base', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_so_quem_edita_o_projeto_troca_a_imagem(): void
    {
        $projeto = $this->projetoDoCoordenador();
        $outro = Usuario::factory()->coordenador()->create();

        $this->actingAs($outro)
            ->put("/projetos-base/{$projeto->id}", $this->dadosValidos(['imagem' => UploadedFile::fake()->image('a.jpg', 800, 450)]))
            ->assertForbidden();

        $this->assertNull($projeto->refresh()->imagem);
    }

    public function test_imagem_de_projeto_publico_abre_sem_login_e_a_de_projeto_oculto_nao(): void
    {
        $publico = $this->projetoDoCoordenador();
        $oculto = $this->projetoDoCoordenador(['publico' => false]);
        $inativo = $this->projetoDoCoordenador(['ativo' => false]);

        foreach ([$publico, $oculto, $inativo] as $projeto) {
            $projeto->trocarImagem(UploadedFile::fake()->image('capa.jpg', 800, 450));
        }

        $this->get($publico->urlImagem())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($oculto->urlImagem())->assertNotFound();
        $this->get($inativo->urlImagem())->assertNotFound();

        // Dentro do sistema, a imagem segue a ProjetoBasePolicy: o estudante não vê projeto inativo.
        $this->actingAs($this->coordenador)->get($oculto->urlImagem())->assertOk();
        $this->actingAs($this->coordenador)->get($inativo->urlImagem())->assertOk();
        $this->actingAs(Usuario::factory()->estudante()->create())->get($inativo->urlImagem())->assertNotFound();

        // Projeto sem imagem não tem arquivo para entregar.
        $this->get('/projetos/'.$this->projetoDoCoordenador()->id.'/imagem')->assertNotFound();
    }

    public function test_foto_de_celular_deitada_fica_em_pe(): void
    {
        $arquivo = $this->jpegComOrientacaoExif(400, 200, 6);

        $projeto = $this->projetoDoCoordenador();
        $projeto->trocarImagem($arquivo);

        [$largura, $altura] = getimagesizefromstring(Storage::disk('local')->get($projeto->imagem));
        $this->assertSame([200, 400], [$largura, $altura]);
    }

    /**
     * JPEG com o bloco EXIF que os celulares gravam para dizer que a foto deve ser girada.
     */
    private function jpegComOrientacaoExif(int $largura, int $altura, int $orientacao): UploadedFile
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        ob_start();
        imagejpeg($imagem);
        $jpeg = (string) ob_get_clean();

        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientacao).pack('v', 0)
            .pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return UploadedFile::fake()->createWithContent('celular.jpg', "\xFF\xD8".$app1.substr($jpeg, 2));
    }
}
