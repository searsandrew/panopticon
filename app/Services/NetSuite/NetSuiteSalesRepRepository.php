<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Log;
use Searsandrew\BriarRose\Facades\BriarRose;
use Throwable;

class NetSuiteSalesRepRepository
{
    private const PAGE_LIMIT = 1000;

    /**
     * @return array<int, array{id: int, name: string, email: string|null}>
     */
    public function active(): array
    {
        $salesRepIds = $this->activeSalesRepIds();

        if ($salesRepIds === []) {
            return [];
        }

        return $this->employeeDetails($salesRepIds);
    }

    /**
     * @return array<int, int>
     */
    private function activeSalesRepIds(): array
    {
        $salesRepIds = [];

        try {
            foreach (BriarRose::rest()->record('employee')->listAll([
                'limit' => self::PAGE_LIMIT,
                'q' => 'issalesrep IS true AND isInactive IS false',
            ]) as $page) {
                $page->throw();

                $items = $page->json('items') ?? [];

                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $salesRepId = is_array($item) ? ($item['id'] ?? null) : null;

                    if (is_numeric($salesRepId) && (int) $salesRepId > 0) {
                        $salesRepIds[] = (int) $salesRepId;
                    }
                }
            }
        } catch (Throwable $throwable) {
            Log::warning('Unable to load NetSuite sales rep employee ids.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }

        return collect($salesRepIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $salesRepIds
     * @return array<int, array{id: int, name: string, email: string|null}>
     */
    private function employeeDetails(array $salesRepIds): array
    {
        $salesRepIds = $this->normalizeSalesRepIds($salesRepIds);

        if ($salesRepIds === []) {
            return [];
        }

        try {
            $page = BriarRose::rest()
                ->suiteql()
                ->query($this->employeeDetailsQuery($salesRepIds), [
                    'limit' => count($salesRepIds),
                    'offset' => 0,
                ], [
                    'Prefer' => 'transient',
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::warning('Unable to load NetSuite sales rep employee details.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return collect($salesRepIds)
                ->map(fn (int $salesRepId): array => [
                    'id' => $salesRepId,
                    'name' => __('Employee #:id', ['id' => $salesRepId]),
                    'email' => null,
                ])
                ->all();
        }

        $items = $page['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (array $employee): ?array {
                $employeeId = $employee['id'] ?? null;

                if (! is_numeric($employeeId)) {
                    return null;
                }

                $name = (string) ($employee['altname'] ?: $employee['entityid'] ?: __('Employee #:id', ['id' => (int) $employeeId]));
                $email = $employee['email'] ?? null;

                return [
                    'id' => (int) $employeeId,
                    'name' => $name,
                    'email' => is_string($email) && trim($email) !== '' ? $email : null,
                ];
            })
            ->filter()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function employeeDetailsQuery(array $salesRepIds): string
    {
        return sprintf(<<<'SQL'
            SELECT id, entityid, altname, email
            FROM entity
            WHERE isinactive = 'F'
                AND id IN (%s)
            ORDER BY altname, entityid
        SQL, implode(', ', $this->normalizeSalesRepIds($salesRepIds)));
    }

    /**
     * @param  array<int, mixed>  $salesRepIds
     * @return array<int, int>
     */
    private function normalizeSalesRepIds(array $salesRepIds): array
    {
        return collect($salesRepIds)
            ->filter(fn (mixed $salesRepId): bool => is_numeric($salesRepId) && (int) $salesRepId > 0)
            ->map(fn (mixed $salesRepId): int => (int) $salesRepId)
            ->unique()
            ->values()
            ->all();
    }
}
