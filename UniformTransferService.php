<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\Axapta\Client;
use App\Clients\Axapta\ErrorException;
use App\Clients\Axapta\Schema\UniformJournalCreate;
use App\Clients\Axapta\Schema\UniformJournalUpdate;
use App\Enums\Uniforms\TransferType;
use App\Http\Requests\Uniforms\Transfers\CreateRequest;
use App\Http\Requests\Uniforms\Transfers\Line;
use App\Http\Requests\Uniforms\Transfers\UpdateLine;
use App\Http\Requests\Uniforms\Transfers\UpdateRequest;
use App\Http\Responses\ErrorCodes;
use App\Models\Employee;
use App\Models\InventLocation;
use App\Services\Contracts\UniformTransferServiceInterface;
use App\Services\Exceptions\UniformTransferServiceException;
use App\Services\Uniform\UniformTransferDetailsMapper;
use App\ValueObjects\UniformTransfer;
use App\ValueObjects\UniformTransferDetails;
use App\User;
use DateTime;

class UniformTransferService implements UniformTransferServiceInterface
{
    public function __construct(
        protected Client                       $client,
        protected UniformTransferDetailsMapper $uniformTransferMapper
    )
    {
    }

    /**
     * AX wfRequestUniformJournalTable.
     *
     * @param User $user
     * @param string $inventLocationId
     * @param TransferType $type
     * @param DateTime $fromDate
     * @param DateTime $toDate
     * @return UniformTransfer[]
     * @throws ErrorException
     */
    public function getList(User $user, string $inventLocationId, TransferType $type, DateTime $fromDate, DateTime $toDate): array
    {
        $workerGuid = (Employee::getFromUser($user))?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($inventLocationId);

        try {
            $result = $this->client->wfRequestUniformJournalTable(
                $workerGuid,
                $departmentGuid,
                $inventLocationId,
                $type,
                $fromDate,
                $toDate
            );
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_GET_LIST_ERROR, $exception);
        }

        $list = [];
        foreach ($result as $item) {
            $employee = Employee::where('employee_id', $item->getEmployee())->first();

            $list[] = new UniformTransfer([
                'id' => $item->getJournalId(),
                'date' => $item->getTransDate(),
                'invent_location_id' => $item->getLocationId(),
                'posted' => $item->getPosted() === 'Yes',
                'employee_id' => $employee?->employee_id ?? '',
                'employee_guid' => $employee?->employee_guid ?? '',
                'first_name' => $employee?->first_name ?? '',
                'last_name' => $employee?->last_name ?? '',
                'employee_full_name' => sprintf(
                    '%s %s', $employee?->first_name ?? '', $employee?->last_name ?? ''
                ),
                'type' => $type->value,
            ]);
        }

        return $list;
    }

    /**
     * AX wfRequestUniformJournalDetails.
     * AX wfRequestUniformByFRP.
     *
     * @param User $user
     * @param string $inventLocationId
     * @param TransferType $type
     * @param string $id
     * @return UniformTransferDetails
     * @throws ErrorException
     */
    public function getDetails(User $user, string $inventLocationId, TransferType $type, string $id): UniformTransferDetails
    {
        $employee = Employee::getFromUser($user);
        $workerGuid = $employee?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($inventLocationId);

        try {
            $result = $this->client->wfRequestUniformJournalDetails(
                $workerGuid,
                $departmentGuid,
                $id,
                $type
            );
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_GET_DETAILS_ERROR, $exception);
        }

        try {
            $frpResult = $this->client->wfRequestUniformByFRP(
                $workerGuid,
                $departmentGuid,
                $result->getEmployee(),
                $inventLocationId
            );
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_BY_FRP_ERROR, $exception);
        }

        return $this->uniformTransferMapper->map($result, $frpResult, $inventLocationId, $type);
    }

    /**
     * AX wfRequestUniformJournalCreate.
     *
     * @param User $user
     * @param CreateRequest $request
     * @return string
     * @throws ErrorException
     */
    public function create(User $user, CreateRequest $request): string
    {
        $workerGuid = (Employee::getFromUser($user))?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($request->getInventLocationId());

        $preparedLines = [];
        /** @var Line $transferLine */
        foreach ($request->getLines() as $transferLine) {
            $transferLineMapped = [
                'Condition' => $transferLine->getCondition(),
                'ItemId' => $transferLine->getItemId(),
                'Qty' => $transferLine->getQuantity(),
                'ReasonReturn' => $transferLine->getReason(),
            ];
            $preparedLines[] = new UniformJournalCreate($transferLineMapped);
        }

        try {
            return $this->client->wfRequestUniformJournalCreate(
                $workerGuid,
                $departmentGuid,
                $request->getInventLocationId(),
                $request->getEmployeeId(),
                $request->getType(),
                new DateTime($request->getDate()),
                $preparedLines,
            );
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_CREATE_ITEM_ERROR, $exception);
        }
    }

    /**
     * AX wfRequestUniformJournalUpdate.
     *
     * @param User $user
     * @param UpdateRequest $request
     * @param string $id
     * @return bool
     * @throws ErrorException
     */
    public function update(User $user, UpdateRequest $request, string $id): bool
    {
        $workerGuid = (Employee::getFromUser($user))?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($request->getInventLocationId());
        $preparedLines = [];

        /** @var UpdateLine $transferLine */
        foreach ($request->getLines() as $transferLine) {
            $transferLineMapped = [
                'Action' => $transferLine->getAction(),
                'Condition' => $transferLine->getCondition(),
                'ItemId' => $transferLine->getItemId(),
                'LineNum' => $transferLine->getLineNum(),
                'Qty' => $transferLine->getQuantity(),
                'ReasonReturn' => $transferLine->getReason(),
            ];
            $preparedLines[] = new UniformJournalUpdate($transferLineMapped);
        }

        try {
            $result = $this->client->wfRequestUniformJournalUpdate(
                $workerGuid,
                $departmentGuid,
                $id,
                $request->getEmployeeId(),
                $request->getType(),
                $preparedLines
            );
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_UPDATE_ITEM_ERROR, $exception);
        }

        return !$result->getIsError();
    }

    /**
     * AX wfRequestUniformJournalDelete.
     *
     * @param User $user
     * @param string $inventLocationId
     * @param TransferType $type
     * @param string $id
     * @return bool
     * @throws ErrorException
     */
    public function delete(User $user, string $inventLocationId, TransferType $type, string $id): bool
    {
        $workerGuid = (Employee::getFromUser($user))?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($inventLocationId);

        try {
            $response = $this->client->wfRequestUniformJournalDelete($workerGuid, $departmentGuid, $id, $type);
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_DELETE_ITEM_ERROR, $exception);
        }

        return !$response->getIsError();
    }

    /**
     * AX wfRequestUniformJournalPost.
     *
     * @param User $user
     * @param string $inventLocationId
     * @param TransferType $type
     * @param string $id
     * @return bool
     * @throws ErrorException
     */
    public function post(User $user, string $inventLocationId, TransferType $type, string $id): bool
    {
        $workerGuid = (Employee::getFromUser($user))?->employee_guid ?? '';
        $departmentGuid = InventLocation::findDepartmentGuid($inventLocationId);

        try {
            $response = $this->client->wfRequestUniformJournalPost($workerGuid, $departmentGuid, $id, $type);
        } catch (ErrorException $exception) {
            throw new UniformTransferServiceException(ErrorCodes::AXAPTA_UNIFORM_POST_ITEM_ERROR, $exception);
        }

        return !$response->getIsError();
    }
}
