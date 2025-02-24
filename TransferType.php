<?php

namespace App\Enums\Uniforms;

enum TransferType: string
{
    case None = 'None';
    case Issuance = 'Issuance';
    case Return = 'Return';
    case WriteOff = 'WriteOff';
}
