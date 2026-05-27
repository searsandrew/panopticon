<?php

use Livewire\Attributes\Computed;
use Livewire\Component;
use Searsandrew\BriarRose\Facades\BriarRose;

new class extends Component {
    private ?int $netSuiteId = null;
    private int $pageLimit = 100;

    public function mount(): void
    {
        $this->netSuiteId = Auth::user()->netsuite_user_id;
    }

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
            $offset += $this->pageLimit;
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
                'limit' => $this->pageLimit,
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
        SQL, $this->netSuiteId);}
    };
?>

<div>
    <pre>{{ json_encode($this->customers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
