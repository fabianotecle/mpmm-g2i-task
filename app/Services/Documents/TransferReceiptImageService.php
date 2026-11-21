<?php

namespace App\Services\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class TransferReceiptImageService
{
    private const WIDTH = 1080;

    private const HEIGHT = 1500;

    public function generate(
        float $amount,
        string $senderPhone,
        string $recipientPhone,
        Carbon $transferredAt,
        bool $private,
        string $fundingType,
        string $controlCode,
        ?string $senderName = null,
        ?string $recipientName = null,
    ): array {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required to generate transfer receipt images.');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($image, 255, 255, 255);
        $blue = imagecolorallocate($image, 18, 111, 169);
        $dark = imagecolorallocate($image, 23, 50, 77);
        $muted = imagecolorallocate($image, 72, 96, 113);
        $light = imagecolorallocate($image, 237, 246, 252);
        $gold = imagecolorallocate($image, 255, 181, 21);
        imagefill($image, 0, 0, $white);
        imagefilledrectangle($image, 0, 0, self::WIDTH, 260, $blue);
        imagefilledrectangle($image, 0, 260, self::WIDTH, 269, $gold);

        $regular = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $this->logo($image, public_path('assets/whatsapp/logo.jpg'), 42, 48, 145);
        $this->text($image, 34, 215, 94, $white, $bold, 'My Phone');
        $this->text($image, 34, 215, 142, $white, $bold, 'My Money®');
        $this->text($image, 25, 215, 190, $gold, $regular, 'Transferência '.($private ? 'privada' : 'pública'));
        $this->centeredWithinFit($image, 28, 94, $white, $bold, 'MyPhone MyMoney®', 610, 1018);
        $this->centeredWithinFit($image, 21, 143, $white, $regular, 'WhatsApp 0800 000 0000', 610, 1018);

        $this->centered($image, 44, 330, $blue, $bold, 'TRANSFERÊNCIA REALIZADA');
        $this->centered($image, 30, 380, $muted, $regular, 'com segurança');
        $this->centered($image, 65, 480, $dark, $bold, 'R$ '.number_format($amount, 2, ',', '.'));
        $this->centered($image, 28, 535, $muted, $regular, $transferredAt->format('d/m/Y H:i:s O'));

        $this->accountBox($image, 590, 'DE', $senderPhone, $private ? null : $senderName, $light, $dark, $muted, $regular, $bold);
        $this->accountBox($image, 785, 'PARA', $recipientPhone, $private ? null : $recipientName, $light, $dark, $muted, $regular, $bold);

        $this->centered($image, 20, 1006, $muted, $bold, 'CÓDIGO DA TRANSFERÊNCIA');
        $this->centeredFit($image, 21, 1046, $muted, $regular, $controlCode, 900);
        $this->centered($image, 27, 1115, $muted, $regular, $fundingType);
        $this->centered($image, 29, 1165, $dark, $bold, 'Envio: '.($private ? 'Privado' : 'Público'));
        if ($private) {
            $this->centeredFit($image, 18, 1220, $muted, $regular, 'Os nomes e os números completos foram ocultados por decisão da origem por se tratar de uma transferência privada.', 940);
        }
        $this->centered($image, 31, 1320, $blue, $bold, 'MyPhone MyMoney®');
        $this->centered($image, 27, 1368, $dark, $bold, 'Meu Celular Meu Dinheiro®');
        $this->centered($image, 27, 1417, $blue, $regular, '+55 0800 000 0000');

        $storedFilename = 'transferencia-mpmm-'.Str::uuid().'.png';
        $path = 'transfer-receipt-images/'.$storedFilename;
        ob_start();
        imagepng($image, null, 6);
        $contents = (string) ob_get_clean();
        Storage::disk('local')->put($path, $contents);
        $baseUrl = rtrim((string) config('services.documents.public_url', config('app.url')), '/');

        return [
            'filename' => $storedFilename,
            'path' => Storage::disk('local')->path($path),
            'url' => $baseUrl.'/api/images/transfer-receipts/'.$storedFilename,
        ];
    }

    private function accountBox($image, int $top, string $label, string $phone, ?string $name, int $background, int $dark, int $muted, string $regular, string $bold): void
    {
        imagefilledrectangle($image, 70, $top, 1010, $top + 165, $background);
        imagefilledrectangle($image, 70, $top, 1010, $top + 5, imagecolorallocate($image, 255, 181, 21));
        $this->centered($image, 22, $top + 40, $muted, $bold, $label);
        $this->centeredFit($image, 38, $top + 98, $dark, $bold, $phone, 870);
        if ($name) {
            $this->centeredFit($image, 23, $top + 140, $muted, $regular, $name, 870);
        }
    }

    private function logo($image, string $path, int $x, int $y, int $size): void
    {
        if (! is_file($path) || ! function_exists('imagecreatefromjpeg')) {
            return;
        }

        $source = @imagecreatefromjpeg($path);
        if (! $source) {
            return;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $crop = min($sourceWidth, $sourceHeight);
        $sourceX = (int) (($sourceWidth - $crop) / 2);
        $sourceY = (int) (($sourceHeight - $crop) / 2);
        imagecopyresampled($image, $source, $x, $y, $sourceX, $sourceY, $size, $size, $crop, $crop);
    }

    private function centered($image, int $size, int $baseline, int $color, string $font, string $text): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $this->text($image, $size, (int) ((self::WIDTH - $width) / 2), $baseline, $color, $font, $text);
    }

    private function centeredFit($image, int $size, int $baseline, int $color, string $font, string $text, int $maxWidth): void
    {
        while ($size > 12) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxWidth) {
                break;
            }
            $size--;
        }

        $this->centered($image, $size, $baseline, $color, $font, $text);
    }

    private function centeredWithinFit($image, int $size, int $baseline, int $color, string $font, string $text, int $left, int $right): void
    {
        $maxWidth = $right - $left;
        while ($size > 12) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (($box[2] - $box[0]) <= $maxWidth) {
                break;
            }
            $size--;
        }

        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];
        $this->text($image, $size, (int) ($left + (($maxWidth - $width) / 2)), $baseline, $color, $font, $text);
    }

    private function text($image, int $size, int $x, int $baseline, int $color, string $font, string $text): void
    {
        imagettftext($image, $size, 0, $x, $baseline, $color, $font, $text);
    }
}
