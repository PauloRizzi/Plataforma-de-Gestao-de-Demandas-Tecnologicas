<?php

namespace App\Models\Concerns;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fase 9: imagem enviada pelo usuário (imagem do Projeto Base e foto de perfil).
 *
 * A imagem é aberta, redimensionada e regravada em JPEG antes de ir para o disco "local"
 * (fora da pasta pública). Isso deixa o arquivo leve e descarta o que vinha junto do original,
 * como a localização GPS das fotos de celular ou conteúdo escondido no arquivo.
 */
trait TemImagem
{
    /** Tipos aceitos no envio. */
    public const IMAGEM_EXTENSOES = ['jpg', 'jpeg', 'png', 'webp'];

    /** Tamanho máximo do arquivo enviado, em MB. */
    public const IMAGEM_TAMANHO_MAXIMO_MB = 5;

    /**
     * Regras de validação do campo de imagem.
     *
     * @return array<int, string>
     */
    public static function regrasDaImagem(): array
    {
        return [
            'image',
            'mimes:'.implode(',', self::IMAGEM_EXTENSOES),
            'max:'.self::IMAGEM_TAMANHO_MAXIMO_MB * 1024,
            'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000',
        ];
    }

    /**
     * Grava a nova imagem na coluna e apaga a anterior.
     *
     * @param  bool  $quadrada  recorta o centro num quadrado (foto de perfil) em vez de só reduzir
     */
    protected function gravarImagem(UploadedFile $arquivo, string $coluna, string $pasta, int $tamanho, bool $quadrada): void
    {
        $conteudo = (string) file_get_contents($arquivo->getRealPath());
        $original = @imagecreatefromstring($conteudo);

        if (! $original instanceof GdImage) {
            throw ValidationException::withMessages([$coluna => 'Não foi possível abrir a imagem. Envie um arquivo JPG, PNG ou WebP.']);
        }

        $imagem = $this->redimensionar($this->corrigirOrientacao($original, $conteudo), $tamanho, $quadrada);

        ob_start();
        imagejpeg($imagem, null, 85);
        $jpeg = (string) ob_get_clean();

        $caminho = "{$pasta}/".Str::random(40).'.jpg';
        Storage::disk('local')->put($caminho, $jpeg);

        $anterior = $this->getAttribute($coluna);
        $this->forceFill([$coluna => $caminho])->save();

        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }
    }

    /**
     * Volta para a imagem padrão.
     */
    protected function apagarImagem(string $coluna): void
    {
        $anterior = $this->getAttribute($coluna);

        if ($anterior) {
            $this->forceFill([$coluna => null])->save();
            Storage::disk('local')->delete($anterior);
        }
    }

    /**
     * Reduz a imagem para caber em $tamanho px. Na quadrada, recorta o centro antes.
     * Fundo branco para PNG e WebP transparentes, porque o JPEG não tem transparência.
     */
    private function redimensionar(GdImage $original, int $tamanho, bool $quadrada): GdImage
    {
        $largura = imagesx($original);
        $altura = imagesy($original);
        $origemX = 0;
        $origemY = 0;

        if ($quadrada) {
            $lado = min($largura, $altura);
            $origemX = intdiv($largura - $lado, 2);
            $origemY = intdiv($altura - $lado, 2);
            $largura = $altura = $lado;
        }

        $escala = min(1, $tamanho / max($largura, $altura));
        $novaLargura = max(1, (int) round($largura * $escala));
        $novaAltura = max(1, (int) round($altura * $escala));

        $nova = imagecreatetruecolor($novaLargura, $novaAltura);
        imagefill($nova, 0, 0, (int) imagecolorallocate($nova, 255, 255, 255));
        imagecopyresampled($nova, $original, 0, 0, $origemX, $origemY, $novaLargura, $novaAltura, $largura, $altura);

        return $nova;
    }

    /**
     * Fotos de celular vêm deitadas, com a rotação só anotada no EXIF; aqui ela é aplicada.
     */
    private function corrigirOrientacao(GdImage $imagem, string $conteudo): GdImage
    {
        return match ($this->orientacaoExif($conteudo)) {
            3 => imagerotate($imagem, 180, 0),
            6 => imagerotate($imagem, -90, 0),
            8 => imagerotate($imagem, 90, 0),
            default => $imagem,
        } ?: $imagem;
    }

    /**
     * Lê a orientação (tag 0x0112) do EXIF de um JPEG, sem depender da extensão exif do PHP.
     */
    private function orientacaoExif(string $conteudo): int
    {
        if (! str_starts_with($conteudo, "\xFF\xD8")) {
            return 1;
        }

        $tamanhoTotal = strlen($conteudo);
        $posicao = 2;

        while ($posicao + 4 <= $tamanhoTotal && $conteudo[$posicao] === "\xFF") {
            $marcador = ord($conteudo[$posicao + 1]);
            $tamanhoBloco = $this->lerNumero('n', $conteudo, $posicao + 2) ?? 0;

            if ($marcador === 0xE1 && substr($conteudo, $posicao + 4, 6) === "Exif\0\0") {
                $tiff = substr($conteudo, $posicao + 10, max(0, $tamanhoBloco - 8));
                $intel = str_starts_with($tiff, 'II');
                $curto = $intel ? 'v' : 'n';

                $diretorio = $this->lerNumero($intel ? 'V' : 'N', $tiff, 4) ?? 0;
                $entradas = $this->lerNumero($curto, $tiff, $diretorio) ?? 0;

                for ($i = 0; $i < $entradas; $i++) {
                    $entrada = $diretorio + 2 + $i * 12;

                    if ($this->lerNumero($curto, $tiff, $entrada) === 0x0112) {
                        return $this->lerNumero($curto, $tiff, $entrada + 8) ?? 1;
                    }
                }

                return 1;
            }

            if ($tamanhoBloco < 2) {
                break;
            }

            // Início dos dados da imagem: não há mais cabeçalhos para ler.
            if ($marcador === 0xDA) {
                break;
            }

            $posicao += 2 + $tamanhoBloco;
        }

        return 1;
    }

    /**
     * Número binário de 2 (n/v) ou 4 (N/V) bytes; nulo se o arquivo acabar antes.
     */
    private function lerNumero(string $formato, string $dados, int $posicao): ?int
    {
        $bytes = in_array($formato, ['n', 'v'], true) ? 2 : 4;

        if ($posicao < 0 || $posicao + $bytes > strlen($dados)) {
            return null;
        }

        return unpack($formato, substr($dados, $posicao, $bytes))[1];
    }
}
