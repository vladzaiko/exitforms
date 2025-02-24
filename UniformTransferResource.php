<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Contracts\FilterableResourceInterface;

class UniformTransferResource extends MappedResource implements FilterableResourceInterface
{
    public static function getFieldsMap(): array
    {
        return [
            'id' => 'id',
            'date' => 'date',
            'inventLocationId' => 'invent_location_id',
            'posted' => 'posted',
            'type' => 'type',
            'employeeId' => 'employee_id',
            'employeeGuid' => 'employee_guid',
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'employeeFullName' => 'employee_full_name',
        ];
    }

    public static function getFilterRules(): array
    {
        return [
            'id' => 'string',
            'date' => 'string',
            'inventLocationId' => 'string',
            'posted' => 'boolean',
            'type' => 'int',
            'employeeId' => 'string',
            'firstName' => 'string',
            'lastName' => 'string',
            'employeeFullName' => 'string',
        ];
    }

    public static function getCasts(): array
    {
        return [];
    }

    public static function getFilterCasts(): array
    {
        return [];
    }
}
