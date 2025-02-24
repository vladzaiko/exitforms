<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * @property-read string $id
 * @property-read string $date
 * @property-read string $invent_location_id
 * @property-read boolean $posted
 * @property-read string $employeeId
 * @property-read string $employeeGuid
 * @property-read string $firstName
 * @property-read string $lastName
 * @property-read int $type
 */
class UniformTransfer extends AccessibleObject
{
    protected $id;
    protected $date;
    protected $invent_location_id;
    protected $posted;
    protected $employee_id;
    protected $employee_guid;
    protected $first_name;
    protected $last_name;
    protected $employee_full_name;
    protected $type;

    public function getEmployeeId(): string
    {
        return (string) $this->employeeId;
    }

    public function getEmployeeGuid(): string
    {
        return (string) $this->employeeGuid;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getInventLocationId(): string
    {
        return (string) $this->invent_location_id;
    }

    public function getPosted(): bool
    {
        return (bool) $this->posted;
    }

    public function getDate(): string
    {
        return (string) $this->date;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    public function getEmployeeFullName()
    {
        return $this->employee_full_name;
    }

    public function getType()
    {
        return $this->type;
    }
}
