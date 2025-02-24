<?php

declare(strict_types=1);

namespace App\ValueObjects\Factories;

use App\Clients\Axapta\Factories\Factory;
use App\Clients\Axapta\Schema\NoYes;
use App\Enums\Uniforms\TransferType;
use App\ValueObjects\UniformTransfer;

class UniformTransferFactory extends Factory
{
    protected string $model = UniformTransfer::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->numerify('JID-######'),
            'date' => $this->faker->date,
            'invent_location_id' => $this->faker->bothify('???-######'),
            'employee_id' => $this->faker->randomDigit(),
            'first_name' => $this->faker->lastName,
            'last_name' => $this->faker->name,
            'type' => ($this->faker->randomElement(TransferType::cases()))->value,
            'posted' => $this->faker->boolean,
        ];
    }
}
