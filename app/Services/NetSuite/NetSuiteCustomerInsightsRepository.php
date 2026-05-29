<?php

namespace App\Services\NetSuite;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Searsandrew\BriarRose\Facades\BriarRose;
use Throwable;

class NetSuiteCustomerInsightsRepository
{
    private const HISTORY_MONTHS = 12;

    private const NEW_ITEM_LIMIT = 6;

    /**
     * @return array<int, array{period: string, label: string, amount: float}>
     */
    public function purchaseHistory(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        try {
            $page = BriarRose::rest()
                ->suiteql()
                ->query($this->purchaseHistoryQuery($customerId), [
                    'limit' => self::HISTORY_MONTHS,
                    'offset' => 0,
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::warning('Unable to load NetSuite customer purchase history.', [
                'customer_id' => $customerId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }

        $items = $page['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (array $item): ?array {
                $period = (string) ($item['period'] ?? '');

                if ($period === '') {
                    return null;
                }

                return [
                    'period' => $period,
                    'label' => $this->historyPeriodLabel($period),
                    'amount' => round((float) ($item['amount'] ?? 0), 2),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{item_id: int|null, itemid: string, name: string, released_at: string|null}>
     */
    public function newlyReleasedItemsNotPurchased(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        try {
            $page = BriarRose::rest()
                ->suiteql()
                ->query($this->newlyReleasedItemsNotPurchasedQuery($customerId), [
                    'limit' => self::NEW_ITEM_LIMIT,
                    'offset' => 0,
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::warning('Unable to load NetSuite customer product gaps.', [
                'customer_id' => $customerId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return [];
        }

        $items = $page['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (array $item): array {
                $displayName = (string) ($item['displayname'] ?? '');
                $itemId = (string) ($item['itemid'] ?? '');
                $releasedAt = $item['released_at'] ?? null;

                return [
                    'item_id' => is_numeric($item['item_id'] ?? null) ? (int) $item['item_id'] : null,
                    'itemid' => $itemId,
                    'name' => $displayName !== '' ? $displayName : $itemId,
                    'released_at' => is_string($releasedAt) && $releasedAt !== '' ? $releasedAt : null,
                ];
            })
            ->filter(fn (array $item): bool => $item['name'] !== '')
            ->values()
            ->all();
    }

    private function purchaseHistoryQuery(int $customerId): string
    {
        return sprintf(<<<'SQL'
            SELECT TO_CHAR(t.trandate, 'YYYY-MM') AS period, SUM(ABS(t.foreigntotal)) AS amount
            FROM transaction t
            WHERE t.entity = %d
                AND t.type IN ('CustInvc', 'CashSale', 'SalesOrd')
                AND t.trandate >= ADD_MONTHS(CURRENT_DATE, -11)
            GROUP BY TO_CHAR(t.trandate, 'YYYY-MM')
            ORDER BY period
        SQL, $customerId);
    }

    private function newlyReleasedItemsNotPurchasedQuery(int $customerId): string
    {
        return sprintf(<<<'SQL'
            SELECT i.id AS item_id, i.itemid, i.displayname, TO_CHAR(i.createddate, 'YYYY-MM-DD') AS released_at
            FROM item i
            WHERE i.isinactive = 'F'
                AND i.createddate >= ADD_MONTHS(CURRENT_DATE, -6)
                AND i.id NOT IN (
                    SELECT tl.item
                    FROM transactionline tl
                    INNER JOIN transaction t ON t.id = tl.transaction
                    WHERE t.entity = %d
                        AND t.type IN ('CustInvc', 'CashSale', 'SalesOrd')
                        AND tl.item IS NOT NULL
                )
            ORDER BY i.createddate DESC, i.itemid
        SQL, $customerId);
    }

    private function historyPeriodLabel(string $period): string
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->format('M Y');
        } catch (Throwable) {
            return $period;
        }
    }
}
