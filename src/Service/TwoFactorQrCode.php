<?php

declare(strict_types=1);

namespace App\Service;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

class TwoFactorQrCode
{
    public function dataUri(string $provisioningUri): string
    {
        return (new SvgWriter())->write(new QrCode(data: $provisioningUri, size: 256, margin: 20))->getDataUri();
    }
}
