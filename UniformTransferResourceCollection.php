<?php

declare(strict_types=1);

namespace App\Http\Resources\Collections;

use App\Http\Resources\UniformTransferResource;

class UniformTransferResourceCollection extends PaginatedCollection
{
    public const RESOURCE_CLASS = UniformTransferResource::class;
}
