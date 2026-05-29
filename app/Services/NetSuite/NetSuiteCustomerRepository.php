<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Arr;
use Searsandrew\BriarRose\Facades\BriarRose;

class NetSuiteCustomerRepository
{
    private const PAGE_LIMIT = 1000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeForSalesRep(int $salesRepId): array
    {
        return $this->activeForSalesRepScope([$salesRepId]);
    }

    /**
     * @param  array<int, int>  $salesRepIds
     * @return array<int, array<string, mixed>>
     */
    public function activeForSalesRepScope(array $salesRepIds): array
    {
        $salesRepIds = $this->normalizeSalesRepIds($salesRepIds);

        if ($salesRepIds === []) {
            return [];
        }

        return $this->customersForQuery($this->activeCustomersQuery($salesRepIds));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pipelineForSalesRep(int $salesRepId): array
    {
        return $this->pipelineForSalesRepScope([$salesRepId]);
    }

    /**
     * @param  array<int, int>  $salesRepIds
     * @return array<int, array<string, mixed>>
     */
    public function pipelineForSalesRepScope(array $salesRepIds): array
    {
        $salesRepIds = $this->normalizeSalesRepIds($salesRepIds);

        if ($salesRepIds === []) {
            return [];
        }

        return $this->customersForQuery($this->pipelineCustomersQuery($salesRepIds));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByAccountNumber(string $accountNumber): ?array
    {
        $accountNumber = trim($accountNumber);

        if ($accountNumber === '') {
            return null;
        }

        return $this->customersForQuery($this->customerByAccountNumberQuery($accountNumber))[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customersForQuery(string $suiteQl): array
    {
        $customers = [];
        $offset = 0;

        do {
            $page = $this->customerPage($suiteQl, $offset);
            $items = $page['items'] ?? [];

            if (is_array($items)) {
                array_push($customers, ...array_map($this->normalizeCustomer(...), $items));
            }

            $hasMore = (bool) ($page['hasMore'] ?? false);
            $offset += self::PAGE_LIMIT;
        } while ($hasMore);

        return $customers;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPage(string $suiteQl, int $offset): array
    {
        $page = BriarRose::rest()
            ->suiteql()
            ->query($suiteQl, [
                'limit' => self::PAGE_LIMIT,
                'offset' => $offset,
            ])
            ->throw()
            ->json();

        return is_array($page) ? $page : [];
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<string, mixed>
     */
    private function normalizeCustomer(array $customer): array
    {
        $cadenceScriptId = Arr::get($customer, 'cadence_scriptid');

        return array_merge($customer, [
            'cadence_iso8601' => is_string($cadenceScriptId) ? ltrim($cadenceScriptId, '_') : null,
        ]);
    }

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function activeCustomersQuery(array $salesRepIds): string
    {
        return sprintf(<<<'SQL'
            SELECT %s FROM customer c LEFT JOIN CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS cadence ON cadence.id = c.custentity_panopticon_comm_cadence WHERE c.isinactive = 'F' AND c.salesrep IN (%s) ORDER BY c.entityid
        SQL, $this->customerSelectColumns(), $this->salesRepIdList($salesRepIds));
    }

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function pipelineCustomersQuery(array $salesRepIds): string
    {
        return sprintf(<<<'SQL'
            SELECT %s FROM customer c LEFT JOIN CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS cadence ON cadence.id = c.custentity_panopticon_comm_cadence WHERE c.isinactive = 'F' AND c.custentity_panopticon_sales_pipeline IN (%s) ORDER BY c.entityid
        SQL, $this->customerSelectColumns(), $this->salesRepIdList($salesRepIds));
    }

    private function customerByAccountNumberQuery(string $accountNumber): string
    {
        return sprintf(<<<'SQL'
            SELECT %s FROM customer c LEFT JOIN CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS cadence ON cadence.id = c.custentity_panopticon_comm_cadence WHERE c.isinactive = 'F' AND c.custentity3 = '%s' ORDER BY c.entityid
        SQL, $this->customerSelectColumns(), $this->escapeSuiteQlString($accountNumber));
    }

    private function customerSelectColumns(): string
    {
        return 'c.id AS customer_id, c.custentity3 AS account_number, c.entityid, c.companyname, c.firstname, c.lastname, c.email, c.phone, c.category AS category_id, BUILTIN.DF(c.category) AS category_name, c.salesrep AS sales_rep_id, BUILTIN.DF(c.salesrep) AS sales_rep_name, c.custentity_panopticon_sales_pipeline AS pipeline_owner_id, BUILTIN.DF(c.custentity_panopticon_sales_pipeline) AS pipeline_owner_name, c.custentity_panopticon_comm_cadence AS cadence_id, BUILTIN.DF(c.custentity_panopticon_comm_cadence) AS cadence_name, cadence.scriptid AS cadence_scriptid';
    }

    private function escapeSuiteQlString(string $value): string
    {
        return str_replace("'", "''", $value);
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

    /**
     * @param  array<int, int>  $salesRepIds
     */
    private function salesRepIdList(array $salesRepIds): string
    {
        return implode(', ', $this->normalizeSalesRepIds($salesRepIds));
    }
}
