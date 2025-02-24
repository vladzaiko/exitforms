<?php

namespace App\Http\Controllers;

use App\Enums\Uniforms\TransferType;
use App\Http\RequestFilters;
use App\Http\Requests\Uniforms\Transfers\CreateRequest;
use App\Http\Requests\Uniforms\Transfers\Line;
use App\Http\Requests\Uniforms\Transfers\UpdateRequest;
use App\Http\Resources\Collections\UniformTransferResourceCollection;
use App\Http\Resources\UniformResource;
use App\Http\Resources\UniformTransferDetailsResource;
use App\Http\Resources\UniformTransferResource;
use App\Http\Responses\FailedResponse;
use App\Http\Responses\JsonResponse;
use App\Http\Responses\SuccessfulResponse;
use App\Models\Employee;
use App\Models\InventLocation;
use App\Services\UniformTransferService;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UniformTransferController extends Controller
{
    public function __construct(
        private readonly UniformTransferService $uniformTransferService,
    ) {
    }

    /**
     * The list of uniform's transferring journals.
     *
     * @param Request $request
     * @return UniformTransferResourceCollection|FailedResponse
     */
    public function index(Request $request): UniformTransferResourceCollection|FailedResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'type' => ['required', Rule::enum(TransferType::class)],
            'fromDate' => ['required', 'date', 'date_format:Y-m-d'],
            'toDate' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:fromDate'],
        ]);

        $validatedData['type'] = TransferType::from($validatedData['type']);
        $perPage = (int) $request->input('perPage', self::DEFAULT_PER_PAGE);
        $page = (int) $request->input('page', 1);

        try {
            $data = $this->uniformTransferService->getList(
                $this->user(),
                $validatedData['inventLocationId'],
                $validatedData['type'],
                new DateTime($validatedData['fromDate']),
                new DateTime($validatedData['toDate']),
            );

            $filters = RequestFilters::fromRequest($request, UniformTransferResource::class);
            $filteredList = $filters->apply(collect($data));
            $sortedList = $filteredList->sortByDesc(function ($item) {
                return $item->date;
            });
            $paginator = new LengthAwarePaginator($sortedList->forPage($page, $perPage), count($sortedList), $perPage, $page);

            return UniformTransferResourceCollection::make($paginator);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show detailed info of one uniform's transfer.
     *
     * @param Request $request
     * @param string $id
     * @return UniformTransferDetailsResource|FailedResponse
     */
    public function show(Request $request, string $id): UniformTransferDetailsResource|FailedResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'type' => ['required', 'string', Rule::enum(TransferType::class)]
        ]);
        $type = TransferType::from($validatedData['type']);

        try {
            $uniforms = $this->uniformTransferService->getDetails(
                $this->user(),
                $validatedData['inventLocationId'],
                $type,
                $id,
            );

            return UniformTransferDetailsResource::make($uniforms);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Creating a journal of transfers.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'date' => ['required', 'date', 'date_format:Y-m-d'],
            'employeeId' => ['required', 'string', Rule::exists(Employee::class, 'employee_id')],
            'type' => ['required', Rule::enum(TransferType::class)],
            'lines' => ['required', 'array', 'filled'],
            'lines.*.itemId' => ['required', 'string'],
            'lines.*.condition' => ['required', 'string', Rule::in(UniformResource::STATUSES)],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.reason' => [Rule::requiredIf($request->input('type') === TransferType::Return->value), 'string'],
        ]);

        $createRequest = new CreateRequest($validatedData);

        try {
            $id = $this->uniformTransferService->create($this->user(), $createRequest);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.create'), ['id' => $id], Response::HTTP_CREATED);
    }

    /**
     * Updating a journal of transfers.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'employeeId' => ['required', 'string', Rule::exists(Employee::class, 'employee_id')],
            'type' => ['required', Rule::enum(TransferType::class)],
            'lines' => ['required', 'array', 'filled'],
            'lines.*.action' => ['required', 'string', Rule::in(UniformResource::ACTIONS)],
            'lines.*.condition' => ['required', 'string', Rule::in(UniformResource::STATUSES)],
            'lines.*.itemId' => ['nullable', 'string'],
            'lines.*.lineNum' => ['required', 'numeric'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.reason' => [Rule::requiredIf($request->input('type') === TransferType::Return->value), 'string'],
        ]);

        $updateRequest = new UpdateRequest($validatedData);

        try {
            $this->uniformTransferService->update($this->user(), $updateRequest, $id);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.update'), ['id' => $id]);
    }

    /**
     * Posting the uniform's transfer.
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'type' => ['required', Rule::enum(TransferType::class)],
        ]);

        try {
            $type = TransferType::from($validatedData['type']);
            $inventLocationId = $validatedData['inventLocationId'];
            $this->uniformTransferService->post($this->user(), $inventLocationId, $type, $id);

            return new SuccessfulResponse(__('messages.uniform.transfer.post', ['id' => $id]), ['id' => $id], Response::HTTP_OK);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Deleting a transfer of uniform.
     */
    public function delete(Request $request, string $id): JsonResponse
    {
        $validatedData = $request->validate([
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'type' => ['required', Rule::enum(TransferType::class)],
        ]);

        try {
            $type = TransferType::from($validatedData['type']);
            $this->uniformTransferService->delete($this->user(), $validatedData['inventLocationId'], $type, $id);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.delete', ['id' => $id]), ['id' => $id], Response::HTTP_OK);
    }

    /**
     * Creating a line for uniform's transferring journal.
     */
    public function createLine(Request $request, string $id): JsonResponse
    {
        $validatedData = $request->validate([
            'itemId' => ['required', 'string'],
            'condition' => ['required', 'string', Rule::in(UniformResource::STATUSES)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string'], // TODO: Add check with UniformTransferReason
            'type' => ['required', Rule::enum(TransferType::class)],
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
        ]);

        $lineRequest = new Line($validatedData);

        try {
            $result = $this->uniformTransferService->createLine($this->user(), $lineRequest);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.line.create', ['id' => $id]), ['id' => $id], Response::HTTP_CREATED);
    }

    /**
     * Updating a line for uniform's transferring journal.
     */
    public function updateLine(Request $request, string $id, int $lineNum): JsonResponse
    {
        $validatedData = $request->validate([
            'itemId' => ['required', 'string'],
            'condition' => ['required', 'string', Rule::in(UniformResource::STATUSES)],
            'quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string'], // TODO: Add check with UniformTransferReason
            'type' => ['required', Rule::enum(TransferType::class)],
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
        ]);

        $lineRequest = new Line($validatedData);

        try {
            $result = $this->uniformTransferService->updateLine($this->user(), $lineRequest, $lineNum);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.line.update', ['id' => $id]), ['id' => $id], Response::HTTP_OK);
    }

    /**
     * Deleting a line for uniform's transferring journal.
     */
    public function deleteLine(Request $request, string $id, int $lineNum): JsonResponse
    {
        $validatedData = $request->validate([
            'type' => ['required', Rule::enum(TransferType::class)],
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
        ]);

        try {
            $this->uniformTransferService->deleteLine($this->user(), $id, $lineNum);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new SuccessfulResponse(__('messages.uniform.transfer.line.delete', ['id' => $id]), ['id' => $id], Response::HTTP_OK);
    }

    /**
     * Bulk deleting of the uniform's issuance.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteBulk(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'type' => ['required', Rule::enum(TransferType::class)],
            'inventLocationId' => ['required', 'string', Rule::exists(InventLocation::class, 'invent_location_id')],
            'ids' => ['required', 'array', 'filled'],
            'ids.*' => ['required', 'string'],
        ]);

        $res = [];
        try {
            $ids = $validatedData['ids'];
            $type = TransferType::from($validatedData['type']);
            $inventLocationId = $validatedData['inventLocationId'];

            foreach ($ids as $id) {
                $res[$id] = $this->uniformTransferService->delete($this->user(), $inventLocationId, $type, $id);
            }

            return new SuccessfulResponse(__('messages.uniform.transfer.deleteBulk'), ['results' => $res], Response::HTTP_OK);
        } catch (Throwable $e) {
            return new FailedResponse($e->getMessage(), ['results' => null], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
