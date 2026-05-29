<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Log;
use Searsandrew\BriarRose\Facades\BriarRose;
use Throwable;

class NetSuiteEmployeeResolver
{
    private const MAX_EMAIL_MATCHES = 100;

    /**
     * Resolve an active NetSuite employee profile by verified email address.
     *
     * @return array{id: int, managed_sales_rep_ids: array<int, int>}|null
     */
    public function resolveByEmail(string $email): ?array
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        $page = $this->employeePage($this->employeeByEmailQuery($email), [
            'limit' => self::MAX_EMAIL_MATCHES,
            'offset' => 0,
        ], [
            'email' => $email,
        ]);

        if ($page === null) {
            return null;
        }

        $employees = $page['items'] ?? [];

        if (! is_array($employees)) {
            Log::warning('NetSuite employee email lookup did not return exactly one match.', [
                'email' => $email,
                'matches' => 0,
            ]);

            return null;
        }

        if (($page['hasMore'] ?? false) === true) {
            Log::warning('NetSuite employee email lookup returned too many matches.', [
                'email' => $email,
            ]);

            return null;
        }

        $employees = collect($employees)
            ->filter(fn (array $employee): bool => strcasecmp((string) ($employee['type'] ?? ''), 'Employee') === 0)
            ->values();

        if ($employees->count() !== 1) {
            Log::warning('NetSuite employee email lookup did not return exactly one employee match.', [
                'email' => $email,
                'matches' => $employees->count(),
            ]);

            return null;
        }

        $profile = $this->employeeProfile($employees->first());

        if ($profile === null) {
            return null;
        }

        $managedSalesRepIds = $this->managedSalesRepIdsForEmployee($profile['id']);

        if ($managedSalesRepIds !== null) {
            $profile['managed_sales_rep_ids'] = $managedSalesRepIds;
        }

        return $profile;
    }

    /**
     * Resolve an active NetSuite employee by verified email address.
     */
    public function resolveIdByEmail(string $email): ?int
    {
        return $this->resolveByEmail($email)['id'] ?? null;
    }

    /**
     * @return array<int, int>|null
     */
    public function managedSalesRepIdsForEmployee(int $employeeId): ?array
    {
        if ($employeeId <= 0) {
            return null;
        }

        $managedSalesRepIds = $this->managedSalesRepIdsFromConfiguredMapTable($employeeId)
            ?? $this->managedSalesRepIdsFromSuiteQlColumn('entity', $employeeId)
            ?? $this->managedSalesRepIdsFromSuiteQlColumn('employee', $employeeId)
            ?? $this->managedSalesRepIdsFromRestRecord($employeeId);

        if ($managedSalesRepIds === null) {
            Log::warning('NetSuite managed sales reps could not be read for employee.', [
                'employee_id' => $employeeId,
                'field_id' => $this->managedSalesRepsFieldId(),
            ]);

            return null;
        }

        return $managedSalesRepIds;
    }

    /**
     * @return array<int, int>|null
     */
    private function managedSalesRepIdsFromSuiteQlColumn(string $table, int $employeeId): ?array
    {
        $page = $this->employeePage($this->managedSalesRepColumnQuery($table, $employeeId), [
            'limit' => 1,
            'offset' => 0,
        ], [
            'employee_id' => $employeeId,
            'source' => $table,
        ]);

        if ($page === null) {
            return null;
        }

        $items = $page['items'] ?? [];

        if (! is_array($items) || count($items) !== 1) {
            return null;
        }

        return $this->normalizeMultiSelectIds($items[0]['managed_sales_rep_ids'] ?? null);
    }

    /**
     * @return array<int, int>|null
     */
    private function managedSalesRepIdsFromConfiguredMapTable(int $employeeId): ?array
    {
        $mapTable = config('panopticon.netsuite_managed_sales_reps_map_table');

        if (! is_string($mapTable) || trim($mapTable) === '') {
            return null;
        }

        $mapTable = trim($mapTable);

        if (! preg_match('/^[A-Za-z0-9_]+$/', $mapTable)) {
            Log::warning('NetSuite managed sales reps map table contains unsafe characters.', [
                'map_table' => $mapTable,
            ]);

            return null;
        }

        $page = $this->employeePage($this->managedSalesRepMapTableQuery($mapTable, $employeeId), [
            'limit' => 1000,
            'offset' => 0,
        ], [
            'employee_id' => $employeeId,
            'source' => $mapTable,
        ]);

        if ($page === null) {
            return null;
        }

        $items = $page['items'] ?? [];

        if (! is_array($items)) {
            return null;
        }

        return $this->normalizeMultiSelectIds(collect($items)->pluck('managed_sales_rep_id')->all());
    }

    /**
     * @return array<int, int>|null
     */
    private function managedSalesRepIdsFromRestRecord(int $employeeId): ?array
    {
        try {
            $record = BriarRose::rest()
                ->get('/services/rest/record/v1/employee/'.$employeeId, [
                    'expandSubResources' => 'true',
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::info('NetSuite employee REST record did not expose managed sales reps.', [
                'employee_id' => $employeeId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if (! is_array($record)) {
            return null;
        }

        $managedSalesReps = $record[$this->managedSalesRepsFieldId()]
            ?? $record['custentityManagedSalesReps']
            ?? $record['custentity_managed_sales_reps']
            ?? null;

        if (is_array($managedSalesReps) && array_key_exists('items', $managedSalesReps)) {
            return $this->normalizeMultiSelectIds($managedSalesReps['items']);
        }

        return $this->normalizeMultiSelectIds($managedSalesReps ?? $record['customFieldList'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function employeePage(string $suiteQl, array $options, array $context): ?array
    {
        try {
            $page = BriarRose::rest()
                ->suiteql()
                ->query($suiteQl, $options)
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::warning('Unable to resolve NetSuite employee.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ] + $context);

            return null;
        }

        return is_array($page) ? $page : [];
    }

    /**
     * @param  array<string, mixed>|mixed  $employee
     * @return array{id: int, managed_sales_rep_ids: array<int, int>}|null
     */
    private function employeeProfile(mixed $employee): ?array
    {
        if (! is_array($employee)) {
            return null;
        }

        $employeeId = $employee['id'] ?? null;

        if (! is_numeric($employeeId)) {
            return null;
        }

        return [
            'id' => (int) $employeeId,
            'managed_sales_rep_ids' => $this->normalizeMultiSelectIds(
                $employee['managed_sales_rep_ids'] ?? $employee[$this->managedSalesRepsFieldId()] ?? null,
            ),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function normalizeMultiSelectIds(mixed $value): array
    {
        $ids = [];

        $this->collectMultiSelectIds($value, $ids);

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function collectMultiSelectIds(mixed $value, array &$ids): void
    {
        if (is_int($value) || is_float($value)) {
            $ids[] = (int) $value;

            return;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return;
            }

            if (ctype_digit($value)) {
                $ids[] = (int) $value;

                return;
            }

            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $this->collectMultiSelectIds($decoded, $ids);

                return;
            }

            preg_match_all('/\d+/', $value, $matches);

            foreach ($matches[0] ?? [] as $id) {
                $ids[] = (int) $id;
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach (['id', 'value', 'internalId', 'internalid'] as $key) {
            if (array_key_exists($key, $value)) {
                $this->collectMultiSelectIds($value[$key], $ids);

                return;
            }
        }

        foreach ($value as $key => $item) {
            if ($key === 'links') {
                continue;
            }

            $this->collectMultiSelectIds($item, $ids);
        }
    }

    private function employeeByEmailQuery(string $email): string
    {
        return sprintf(<<<'SQL'
            SELECT id, entityid, altname, email, isinactive, type FROM entity WHERE isinactive = 'F' AND lower(email) = lower('%s') ORDER BY id
        SQL, $this->escapeSqlString($email));
    }

    private function managedSalesRepColumnQuery(string $table, int $employeeId): string
    {
        if (! in_array($table, ['entity', 'employee'], true)) {
            $table = 'entity';
        }

        return sprintf(<<<'SQL'
            SELECT id, %s AS managed_sales_rep_ids FROM %s WHERE isinactive = 'F' AND id = %d
        SQL, $this->managedSalesRepsFieldId(), $table, $employeeId);
    }

    private function managedSalesRepMapTableQuery(string $mapTable, int $employeeId): string
    {
        return sprintf(<<<'SQL'
            SELECT maptwo AS managed_sales_rep_id FROM %s WHERE mapone = %d ORDER BY maptwo
        SQL, $mapTable, $employeeId);
    }

    private function managedSalesRepsFieldId(): string
    {
        $fieldId = config('panopticon.netsuite_managed_sales_reps_field_id', 'custentity_managed_sales_reps');

        if (! is_string($fieldId) || ! preg_match('/^[A-Za-z0-9_]+$/', $fieldId)) {
            return 'custentity_managed_sales_reps';
        }

        return $fieldId;
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
