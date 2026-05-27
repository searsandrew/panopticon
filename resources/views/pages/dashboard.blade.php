<?php

use Livewire\Attributes\Computed;
use Livewire\Component;
use Searsandrew\BriarRose\Facades\BriarRose;

new class extends Component {
    private const SALES_PIPELINE_ID = 2214;

    private const CUSTOMER_PAGE_LIMIT = 1000;

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function customers(): array
    {
        $customers = [];
        $offset = 0;

        do {
            $page = $this->customerPage($offset);
            $items = $page['items'] ?? [];

            if (is_array($items)) {
                $customers = array_merge($customers, $items);
            }

            $hasMore = (bool) ($page['hasMore'] ?? false);
            $offset += self::CUSTOMER_PAGE_LIMIT;
        } while ($hasMore);

        return $customers;
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPage(int $offset): array
    {
        $page = BriarRose::rest()
            ->suiteql()
            ->query($this->salesPipelineCustomersQuery(), [
                'limit' => self::CUSTOMER_PAGE_LIMIT,
                'offset' => $offset,
            ])
            ->throw()
            ->json();

        return is_array($page) ? $page : [];
    }

    private function salesPipelineCustomersQuery(): string
    {
        return sprintf(<<<'SQL'
            SELECT id, entityid, companyname, firstname, lastname, email, custentity_panopticon_sales_pipeline, custentity_panopticon_comm_cadence FROM customer WHERE custentity_panopticon_sales_pipeline = %d ORDER BY id
        SQL, self::SALES_PIPELINE_ID);}
    };
?>

<div>
    <pre>{{ json_encode($this->customers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
