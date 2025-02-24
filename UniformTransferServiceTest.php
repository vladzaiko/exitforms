<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Clients\Axapta\Client;
use App\Clients\Axapta\Factories\UniformTransferDetailsFactory;
use App\Clients\Axapta\Factories\UniformTransferDetailsLineFactory;
use App\Clients\Axapta\Schema\Response;
use App\Clients\Axapta\Schema\UniformByFRP;
use App\Clients\Axapta\Schema\UniformJournalCreate;
use App\Clients\Axapta\Schema\UniformJournalDetails;
use App\Clients\Axapta\Schema\UniformJournalTable;
use App\Clients\Axapta\Schema\UniformJournalUpdate;
use App\Clients\Axapta\Schema\WFUniformJournalDetailsResponse;
use App\Enums\Uniforms\TransferType;
use App\Http\Requests\Uniforms\Transfers\CreateRequest;
use App\Http\Requests\Uniforms\Transfers\Line;
use App\Http\Requests\Uniforms\Transfers\UpdateLine;
use App\Http\Requests\Uniforms\Transfers\UpdateRequest;
use App\Http\Resources\UniformResource;
use App\Models\AxaptaToken;
use App\Models\Employee;
use App\Models\InventLocation;
use App\Models\Nomenclature;
use App\Services\Contracts\UniformTransferServiceInterface;
use App\Services\Uniform\UniformTransferDetailsMapper;
use App\Services\UniformTransferService;
use App\ValueObjects\UniformTransfer;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * @covers UniformTransferService
 */
class UniformTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InventLocation $inventLocation;
    protected Employee $employee;
    protected Client|MockObject $client;
    protected \App\User $user;

    public function setUp(): void
    {
        parent::setUp();

        $workerGuid = $this->faker->uuid;
        $this->user = $this->userWithEmployee($workerGuid);
        $this->employee = $this->user->getEmployee();

        /** @var InventLocation $inventLocation */
        $this->inventLocation = InventLocation::factory()->create([
            'responsible_person_guid' => $workerGuid,
            'country_code' => $this->user->getCountry(),
        ]);

        $this->client = $this->createMock(Client::class);
    }

    /**
     * @covers UniformTransferService::getList
     */
    public function testGetList()
    {
        // Arrange
        $inventLocationId = $this->inventLocation->invent_location_id;
        $type = $this->faker->randomElement(TransferType::cases());
        $fromDate = new DateTime('yesterday');
        $toDate = new DateTime('tomorrow');

        $expected = [
            new UniformJournalTable([
                'Employee' => $this->employee->employee_id,
                'JournalId' => (string)$this->faker->randomNumber(),
                'LocationId' => $inventLocationId,
                'Posted' => $this->faker->randomElement(['Yes', 'No']),
                'TransDate' => (new DateTime())->format('Y-m-d\TH:i:s'),
            ]),
            new UniformJournalTable([
                'Employee' => $this->employee->employee_id,
                'JournalId' => (string)$this->faker->randomNumber(),
                'LocationId' => $inventLocationId,
                'Posted' => $this->faker->randomElement(['Yes', 'No']),
                'TransDate' => (new DateTime())->format('Y-m-d\TH:i:s'),
            ]),
        ];

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalTable')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $inventLocationId,
                $type,
                $fromDate,
                $toDate
            )->willReturn($expected);

        // Act
        $actual = $this->makeSut()->getList($this->user, $inventLocationId, $type, $fromDate, $toDate);

        // Assert
        $this->assertIsArray($actual);
        $this->assertCount(count($expected), $actual);
        foreach ($actual as $item) {
            $this->assertInstanceOf(UniformTransfer::class, $item);
        }
    }

    /**
     * @covers UniformTransferService::getDetails
     *
     * @runInSeparateProcess
     */
    public function testGetDetails()
    {
        // Arrange
        $inventLocationId = $this->inventLocation->invent_location_id;
        $type = $this->faker->randomElement(TransferType::cases());
        $journalId = (string)$this->faker->randomNumber();

        $raw = UniformTransferDetailsFactory::getInstance()->raw([
            'JournalId' => $journalId,
            'Employee' => $this->employee->employee_id,
        ]);

        $response = new WFUniformJournalDetailsResponse($raw);

        $expected = $raw;
        $expected['Items'] = ['UniformJournalDetails' => []];

        $items = $raw['Items'] ?? ['UniformJournalDetails' => (new UniformTransferDetailsLineFactory())->count(3)->raw()];
        $uniformJournalDetails = $items['UniformJournalDetails'];

        foreach ($uniformJournalDetails as $item) {
            $expected['Items']['UniformJournalDetails'][] = new UniformJournalDetails($item);
        }

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalDetails')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $journalId,
                $type
            )->willReturn($response);

        $frpItems = [];
        foreach ($expected['Items']['UniformJournalDetails'] as $item) {
            $frpItems[] = new UniformByFRP([
                'Condition' => $item->getCondition(),
                'FinishUsingDate' => $this->faker->dateTime->format('Y-m-d\TH:i:s'),
                'ItemId' => $item->getItemId(),
                'ItemName' => $this->faker->word,
                'PossibleReturn' => $this->faker->randomElement(['Yes', 'No']),
                'Qty' => $this->faker->randomNumber(),
            ]);
        }

        $this->client->expects($this->once())
            ->method('wfRequestUniformByFRP')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $this->employee->employee_id,
                $this->inventLocation->invent_location_id,
            )->willReturn($frpItems);

        foreach ($expected['Items']['UniformJournalDetails'] as $item) {
            Nomenclature::factory()->create([
                'code' => $item->getItemId(),
            ]);
        }

        // Act
        $actual = $this->makeSut()->getDetails($this->user, $inventLocationId, $type, $journalId);

        // Assert
        $this->assertCount(count($expected['Items']['UniformJournalDetails']), $actual->getLines());
        foreach ($actual->getLines() as $key => $line) {
            if ($type === TransferType::Return) {
                $this->assertEquals($frpItems[$key]->getQty(), $line['availableQuantity']);
            } elseif ($line['condition'] === 'New') {
                $this->assertEquals($expected['Items']['UniformJournalDetails'][$key]->getAvailableQtyNew(), $line['availableQuantity']);
            } else {
                $this->assertEquals($expected['Items']['UniformJournalDetails'][$key]->getAvailableQtyUsed(), $line['availableQuantity']);
            }
        }
    }

    /**
     * @covers UniformTransferService::create
     */
    public function testCreate()
    {
        // Arrange
        $journalId = (string)$this->faker->randomNumber();
        $type = $this->faker->randomElement(TransferType::cases());

        $data = [
            'inventLocationId' => $this->inventLocation->invent_location_id,
            'date' => date('Y-m-d'),
            'employeeId' => $this->employee->employee_id,
            'type' => $type->value,
            'lines' => [
                [
                    'itemId' => $this->faker->text(10),
                    'condition' => $this->faker->randomElement(['Used', 'New']),
                    'quantity' => $this->faker->randomDigit(),
                    'reason' => $this->faker->text(),
                ],
            ],
        ];
        $createRequest = new CreateRequest($data);

        $preparedLines = [];
        /** @var Line $transferLine */
        foreach ($createRequest->getLines() as $transferLine) {
            $transferLineMapped = [
                'Condition' => $transferLine->getCondition(),
                'ItemId' => $transferLine->getItemId(),
                'Qty' => $transferLine->getQuantity(),
                'ReasonReturn' => $transferLine->getReason(),
            ];
            $preparedLines[] = new UniformJournalCreate($transferLineMapped);
        }

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalCreate')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $data['inventLocationId'],
                $data['employeeId'],
                $type,
                new DateTime($data['date']),
                $preparedLines
            )->willReturn($journalId);

        // Act
        $actual = $this->makeSut()->create($this->user, $createRequest);

        // Assert
        $this->assertIsString($actual);
        $this->assertEquals($journalId, $actual);
    }

    /**
     * @covers UniformTransferService::update
     */
    public function testUpdate()
    {
        // Arrange
        $journalId = $this->faker->uuid;

        $data = [
            'inventLocationId' => $this->inventLocation->invent_location_id,
            'employeeId' => $this->employee->employee_id,
            'type' => ($this->faker->randomElement(TransferType::cases()))->value,
            'lines' => [
                [
                    'action' => $this->faker->randomElement(UniformResource::ACTIONS),
                    'itemId' => $this->faker->text(10),
                    'lineNum' => $this->faker->unique()->randomNumber(),
                    'condition' => $this->faker->randomElement(UniformResource::STATUSES),
                    'quantity' => $this->faker->randomDigit(),
                    'reason' => $this->faker->text(),
                ],
            ],
        ];
        $updateRequest = new UpdateRequest($data);

        $preparedLines = [];
        /** @var UpdateLine $transferLine */
        foreach ($updateRequest->getLines() as $transferLine) {
            $transferLineMapped = [
                'Action' => $transferLine->getAction(),
                'Condition' => $transferLine->getCondition(),
                'LineNum' => $transferLine->getLineNum(),
                'ItemId' => $transferLine->getItemId(),
                'Qty' => $transferLine->getQuantity(),
                'ReasonReturn' => $transferLine->getReason(),
            ];
            $preparedLines[] = new UniformJournalUpdate($transferLineMapped);
        }

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalUpdate')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $journalId,
                $updateRequest->getEmployeeId(),
                $updateRequest->getType(),
                $preparedLines
            )->willReturn(new Response(['IsError' => 0]));

        // Act
        $actual = $this->makeSut()->update($this->user, $updateRequest, $journalId);

        // Assert
        $this->assertTrue($actual);
    }

    /**
     * @covers UniformTransferService::delete
     */
    public function testDelete()
    {
        // Arrange
        $id = $this->faker->uuid;
        $inventLocationId = $this->inventLocation->invent_location_id;
        $type = $this->faker->randomElement(TransferType::cases());

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalDelete')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $id,
                $type,
            )->willReturn(new Response(['IsError' => 0]));

        // Act
        $actual = $this->makeSut()->delete($this->user, $inventLocationId, $type, $id);

        // Assert
        $this->assertTrue($actual);
    }

    /**
     * @covers UniformTransferService::createLine
     */
    public function testCreateLine()
    {
        // Arrange
        $data = [
            'itemId' => $this->faker->text(10),
            'condition' => $this->faker->randomElement(['Used', 'New']),
            'quantity' => $this->faker->randomDigit(),
            'reason' => $this->faker->text(),
        ];
        $lineRequest = new Line($data);

        // Act
        $actual = $this->makeSut()->createLine($this->user, $lineRequest);

        // Assert
        $this->assertTrue($actual);
    }

    /**
     * @covers UniformTransferService::updateLine
     */
    public function testUpdateLine()
    {
        // Arrange
        $num = $this->faker->randomNumber();

        $data = [
            'itemId' => $this->faker->text(10),
            'condition' => $this->faker->randomElement(['Used', 'New']),
            'quantity' => $this->faker->randomDigit(),
            'reason' => $this->faker->text(),
        ];
        $lineRequest = new Line($data);

        // Act
        $actual = $this->makeSut()->updateLine($this->user, $lineRequest, $num);

        // Assert
        $this->assertTrue($actual);
    }

    /**
     * @covers UniformTransferService::deleteLine
     */
    public function testDeleteLine()
    {
        // Arrange
        $id = $this->faker->uuid;
        $num = $this->faker->randomNumber();

        // Act
        $actual = $this->makeSut()->deleteLine($this->user, $id, $num);

        // Assert
        $this->assertTrue($actual);
    }

    /**
     * @covers UniformTransferService::post
     */
    public function testPost()
    {
        // Arrange
        $id = $this->faker->uuid;
        $inventLocationId = $this->inventLocation->invent_location_id;
        $type = $this->faker->randomElement(TransferType::cases());

        $this->client->expects($this->once())
            ->method('wfRequestUniformJournalPost')
            ->with(
                $this->employee->employee_guid,
                $this->inventLocation->rfc_guid,
                $id,
                $type,
            )->willReturn(new Response(['IsError' => 0]));

        // Act
        $actual = $this->makeSut()->post($this->user, $inventLocationId, $type, $id);

        // Assert
        $this->assertTrue($actual);
    }

    protected function makeSut(): UniformTransferServiceInterface
    {
        $detailsMapper = $this->app->get(UniformTransferDetailsMapper::class);

        return new UniformTransferService(
            $this->client,
            $detailsMapper
        );
    }
}
