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
        return $this->customersForQuery($this->activeCustomersQuery($salesRepId));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pipelineForSalesRep(int $salesRepId): array
    {
        return $this->customersForQuery($this->pipelineCustomersQuery($salesRepId));
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

    private function activeCustomersQuery(int $salesRepId): string
    {
        return sprintf(<<<'SQL'
            SELECT c.id AS customer_id, c.entityid, c.companyname, c.firstname, c.lastname, c.email, c.phone, c.salesrep AS sales_rep_id, BUILTIN.DF(c.salesrep) AS sales_rep_name, c.custentity_panopticon_sales_pipeline AS pipeline_owner_id, BUILTIN.DF(c.custentity_panopticon_sales_pipeline) AS pipeline_owner_name, c.custentity_panopticon_comm_cadence AS cadence_id, BUILTIN.DF(c.custentity_panopticon_comm_cadence) AS cadence_name, cadence.scriptid AS cadence_scriptid FROM customer c LEFT JOIN CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS cadence ON cadence.id = c.custentity_panopticon_comm_cadence WHERE c.isinactive = 'F' AND c.salesrep = %d ORDER BY c.entityid
        SQL, $salesRepId);
    }

    private function pipelineCustomersQuery(int $salesRepId): string
    {
        return sprintf(<<<'SQL'
            SELECT c.id AS customer_id, c.entityid, c.companyname, c.firstname, c.lastname, c.email, c.phone, c.salesrep AS sales_rep_id, BUILTIN.DF(c.salesrep) AS sales_rep_name, c.custentity_panopticon_sales_pipeline AS pipeline_owner_id, BUILTIN.DF(c.custentity_panopticon_sales_pipeline) AS pipeline_owner_name, c.custentity_panopticon_comm_cadence AS cadence_id, BUILTIN.DF(c.custentity_panopticon_comm_cadence) AS cadence_name, cadence.scriptid AS cadence_scriptid FROM customer c LEFT JOIN CUSTOMLIST_PANOPTICON_CADENCE_OPTIONS cadence ON cadence.id = c.custentity_panopticon_comm_cadence WHERE c.isinactive = 'F' AND c.custentity_panopticon_sales_pipeline = %d ORDER BY c.entityid
        SQL, $salesRepId);
    }
}
