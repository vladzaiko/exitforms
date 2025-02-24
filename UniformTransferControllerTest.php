<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Clients\Axapta\Factories\UniformTransferDetailsFactory;
use App\Clients\Axapta\Schema\WFUniformJournalDetailsResponse;
use App\Enums\Uniforms\TransferType;
use App\Http\Controllers\UniformTransferController;
use App\Http\RequestFilters;
use App\Http\Requests\Uniforms\Transfers\CreateRequest;
use App\Http\Requests\Uniforms\Transfers\Line;
use App\Http\Requests\Uniforms\Transfers\UpdateRequest;
use App\Http\Resources\Collections\UniformTransferResourceCollection;
use App\Http\Resources\UniformResource;
use App\Http\Resources\UniformTransferDetailsResource;
use App\Http\Resources\UniformTransferResource;
use App\Http\Responses\SuccessfulResponse;
use App\Models\Employee;
use App\Models\InventLocation;
use App\Models\Nomenclature;
use App\Services\Uniform\UniformTransferDetailsMapper;
use App\Services\UniformTransferService;
use App\ValueObjects\UniformTransfer;
use Carbon\Carbon;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * @covers UniformTransferController
 */
class UniformTransferControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $inventLocationId;
    protected $inventLocation;
    protected $employee;

    public function setUp(): void
    {
        parent::setUp();

        $workerGuid = $this->faker->uuid;
        $user = $this->user();

        /** @var InventLocation $inventLocation */
        $this->inventLocation = InventLocation::factory()->create([
            'responsible_person_guid' => $workerGuid,
            'country_code' => $user->getCountry(),
        ]);

        $this->inventLocationId = $inventLocationId = $this->inventLocation->invent_location_id;

        /** @var Employee $employee */
        $this->employee = Employee::factory()->create([
            'employee_guid' => $workerGuid,
            'mdmid' => $user->getMdmId(),
            'employee_id' => $user->getEmployeeId(),
            'invent_location' => $this->inventLocation->invent_location_id,
            'country_code' => $user->getCountry(),
        ]);
    }

    /**
     * @covers UniformTransferController::index
     */
    public function testGetList()
    {
        $page = 1;
        $perPage = 2;
        $fromDate = $this->faker->date;
        $toDate = Carbon::parse($fromDate)->addDay()->format('Y-m-d');
        $type = $this->faker->randomElement(TransferType::cases());
        $employeeId = $this->employee->employee_id;
        $request = Request::createFromGlobals();
        $inventLocationId = $this->inventLocationId;
        $request->merge([
            'inventLocationId' => $inventLocationId,
            'type' => $type->value,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
            'page' => $page,
            'perPage' => $perPage,
        ]);

        $data = [];
        for ($i=1; $i<=2; $i++) {
            $data[] = new UniformTransfer([
                'id' => $i,
                'date' => $i%2 ? Carbon::parse($fromDate)->addDay()->format('Y-m-d') : Carbon::parse($toDate)->subDay()->format('Y-m-d'),
                'invent_location_id' => $inventLocationId,
                'posted' => (bool)($i % 2),
                'employee_id' => $employeeId,
                'first_name' => $this->faker->firstName,
                'last_name' => $this->faker->lastName,
                'type' => $i%2 ? TransferType::Issuance->value : TransferType::Return->value,
            ]);
        }

        $items = collect($data);
        $filters = RequestFilters::fromRequest($request, UniformTransferResource::class);
        $filteredList = $filters->apply(collect($items));
        $sortedList = $filteredList->sortByDesc(function ($item) {
            return $item->date;
        });
        $paginator = new LengthAwarePaginator($sortedList->forPage($page, $perPage), count($sortedList), $perPage, $page);
        $expectedCollection = UniformTransferResourceCollection::make($paginator);

        $this->app->singleton(UniformTransferService::class, function () use ($data, $inventLocationId, $type, $fromDate, $toDate) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('getList')
                ->with($this->user(), $inventLocationId, $type, new DateTime($fromDate), new DateTime($toDate))
                ->willReturn($data);

            return $serviceMock;
        });

        $sut = $this->app->get(UniformTransferController::class);
        $actualCollection = $sut->index($request);

        $this->assertEquals($expectedCollection,
            $actualCollection);
    }

    /**
    * @covers UniformTransferController::show
    */
    public function testShow()
    {
        $id = (string) $this->faker->randomDigit();
        $type = $this->faker->randomElement(TransferType::cases());
        $employeeId = $this->employee->employee_id;
        $inventLocationId = $this->inventLocationId;

        $detailsRaw = (new UniformTransferDetailsFactory())->raw(['Id' => $id, 'JournalsType' => $type->value]);
        $details = new WFUniformJournalDetailsResponse($detailsRaw);

        foreach ($details->getItems() as $item) {
            Nomenclature::factory()->createOne([
                'code' => $item->getItemId(),
            ]);
        }

        /* @var UniformTransferDetailsMapper $mapper */
        $mapper = $this->app->get(UniformTransferDetailsMapper::class);
        $mappedDetails = $mapper->map($details, [], $inventLocationId, $type);
        $expected = UniformTransferDetailsResource::make($mappedDetails);

        $this->app->singleton(UniformTransferService::class, function () use ($mappedDetails, $id, $type) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('getDetails')
                ->with($this->user(), $this->inventLocationId, $type, $id)
                ->willReturn($mappedDetails);

            return $serviceMock;
        });

        $sut = $this->app->get(UniformTransferController::class);
        $request = Request::createFromGlobals();
        $request->merge([
            'id' => $id,
            'inventLocationId' => $this->inventLocationId,
            'type' => $type->value,
        ]);
        $actual = $sut->show($request, $id);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::create
     */
    public function testCreate()
    {
        $id = 1;
        $inventLocationId = $this->inventLocationId;
        $user = $this->user();
        $data = [
            'inventLocationId' => $inventLocationId,
            'date' => $this->faker->date,
            'employeeId' => $this->employeeId,
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
            'lines' => [[
                'itemId' => $this->faker->text(20),
                'condition' => $this->faker->randomElement(['Used','New']),
                'quantity' => $this->faker->randomDigit(),
                'reason' => $this->faker->sentence,
            ]],
        ];

        $request = Request::createFromGlobals();
        $request->merge($data);

        $createRequest = new CreateRequest($data);

        $this->app->singleton(UniformTransferService::class, function () use ($createRequest, $user, $id) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('create')
                ->with($user, $createRequest)
                ->willReturn((string) $id);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $actual = $sut->create($request);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.create'), ['id' => (string) $id], Response::HTTP_CREATED);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::update
     */
    public function testUpdate()
    {
        $id = 1;
        $inventLocationId = $this->inventLocationId;
        $user = $this->user();
        $data = [
            'inventLocationId' => $inventLocationId,
            'employeeId' => $this->employeeId,
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
            'lines' => [[
                'action' => $this->faker->randomElement(UniformResource::ACTIONS),
                'itemId' => $this->faker->text(20),
                'lineNum' => $this->faker->unique()->randomNumber(),
                'condition' => $this->faker->randomElement(UniformResource::STATUSES),
                'quantity' => $this->faker->randomDigit(),
                'reason' => $this->faker->sentence,
            ]],
        ];

        $request = Request::createFromGlobals();
        $request->merge($data);

        $updateRequest = new UpdateRequest($data);

        $this->app->singleton(UniformTransferService::class, function () use ($updateRequest, $id, $user) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('update')
                ->with($user, $updateRequest, (string) $id)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $actual = $sut->update($request, (string) $id);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.update'), ['id' => (string) $id], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::post
     */
    public function testPost()
    {
        $id = $this->faker->randomDigit();
        $inventLocationId = $this->inventLocationId;
        $data = [
            'id' => $id,
            'inventLocationId' => $inventLocationId,
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
        ];

        $request = Request::createFromGlobals();
        $request->merge($data);

        $type = TransferType::from($data['type']);
        $this->app->singleton(UniformTransferService::class, function () use ($id, $inventLocationId, $type) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('post')
                ->with($this->user(), $inventLocationId, $type, $id)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $actual = $sut->post($request, (string) $id);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.post', ['id' => $id]), ['id' => (string) $id], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::destroy
     */
    public function testDestroy()
    {
        $id = $this->faker->randomDigit();
        $inventLocationId = $this->inventLocationId;
        $type = $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]);
        $data = [
            'id' => $id,
            'inventLocationId' => $inventLocationId,
            'type' => $type,
        ];

        $this->app->singleton(UniformTransferService::class, function () use ($id, $inventLocationId, $type) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $typeT = TransferType::from($type);
            $serviceMock
                ->expects($this->once())
                ->method('delete')
                ->with($this->user(), $inventLocationId, $typeT, $id)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $request = Request::createFromGlobals();
        $request->merge($data);
        $actual = $sut->delete($request, (string) $id);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.delete', ['id' => $id]), ['id' => (string) $id], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::destroy
     */
    public function testDestroyBulk()
    {
        $id = $this->faker->randomDigit();
        $inventLocationId = $this->inventLocationId;
        $type = $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]);
        $data = [
            'ids' => [
                (string) $id,
            ],
            'inventLocationId' => $inventLocationId,
            'type' => $type,
        ];

        $this->app->singleton(UniformTransferService::class, function () use ($id, $inventLocationId, $type) {
            $typeT = TransferType::from($type);
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->any())
                ->method('delete')
                ->with($this->user(), $inventLocationId, $typeT, $id)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $request = Request::createFromGlobals();
        $request->merge($data);
        $actual = $sut->deleteBulk($request);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.deleteBulk'), ['results' => ["$id" => true]], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::createLine
     */
    public function testCreateLine()
    {
        $id = (string) $this->faker->randomDigit();
        $inventLocationId = $this->inventLocationId;
        $user = $this->user();
        $data = [
            'itemId' => $this->faker->text(20),
            'condition' => $this->faker->randomElement(['Used','New']),
            'quantity' => $this->faker->randomDigit(),
            'reason' => $this->faker->sentence,
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
            'inventLocationId' => $inventLocationId,
        ];

        $lineRequest = new Line($data);

        $this->app->singleton(UniformTransferService::class, function () use ($lineRequest, $id, $user) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('createLine')
                ->with($user, $lineRequest)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $request = Request::createFromGlobals();
        $request->merge($data);
        $actual = $sut->createLine($request, $id);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.line.create', ['id' => $id]), ['id' => $id], Response::HTTP_CREATED);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::updateLine
     */
    public function testUpdateLine()
    {
        $id = (string) $this->faker->randomDigit();
        $lineNum = $this->faker->randomDigit();
        $inventLocationId = $this->inventLocationId;
        $user = $this->user();
        $data = [
            'itemId' => $this->faker->text(20),
            'condition' => $this->faker->randomElement(['Used','New']),
            'quantity' => $this->faker->randomDigit(),
            'reason' => $this->faker->sentence,
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
            'inventLocationId' => $inventLocationId,
        ];

        $lineRequest = new Line($data);

        $this->app->singleton(UniformTransferService::class, function () use ($lineRequest, $id, $lineNum, $user) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('updateLine')
                ->with($user, $lineRequest, $lineNum)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);
        $request = Request::createFromGlobals();
        $request->merge($data);
        $actual = $sut->updateLine($request, $id, $lineNum);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.line.update', ['id' => $id]), ['id' => $id], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }

    /**
     * @covers UniformTransferController::deleteLine
     */
    public function testDeleteLine()
    {
        $inventLocationId = $this->inventLocationId;
        $user = $this->user();
        $id = (string) $this->faker->randomDigit();
        $lineNum = $this->faker->randomDigit();
        $data = [
            'type' => $this->faker->randomElement([TransferType::Return->value, TransferType::Issuance->value]),
            'inventLocationId' => $inventLocationId,
        ];

        $this->app->singleton(UniformTransferService::class, function () use ($id, $lineNum, $user) {
            $serviceMock = $this->createMock(UniformTransferService::class);
            $serviceMock
                ->expects($this->once())
                ->method('deleteLine')
                ->with($user, $id, $lineNum)
                ->willReturn(true);

            return $serviceMock;
        });

        /* @var UniformTransferController $sut */
        $sut = $this->app->get(UniformTransferController::class);

        $request = Request::createFromGlobals();
        $request->merge($data);
        $actual = $sut->deleteLine($request, $id, $lineNum);
        $expected = new SuccessfulResponse(__('messages.uniform.transfer.line.delete', ['id' => $id]), ['id' => $id], Response::HTTP_OK);

        $this->assertEquals($expected, $actual);
    }
}
