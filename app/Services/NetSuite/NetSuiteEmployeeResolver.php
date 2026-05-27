<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Log;
use Searsandrew\BriarRose\Facades\BriarRose;
use Throwable;

class NetSuiteEmployeeResolver
{
    /**
     * Resolve an active NetSuite employee by verified email address.
     */
    public function resolveIdByEmail(string $email): ?int
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        try {
            $page = BriarRose::rest()
                ->suiteql()
                ->query($this->employeeByEmailQuery($email), [
                    'limit' => 2,
                    'offset' => 0,
                ])
                ->throw()
                ->json();
        } catch (Throwable $throwable) {
            Log::warning('Unable to resolve NetSuite employee by email.', [
                'email' => $email,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        $employees = $page['items'] ?? [];

        if (! is_array($employees) || count($employees) !== 1) {
            Log::warning('NetSuite employee email lookup did not return exactly one match.', [
                'email' => $email,
                'matches' => is_array($employees) ? count($employees) : 0,
            ]);

            return null;
        }

        $employeeId = $employees[0]['id'] ?? null;

        return is_numeric($employeeId) ? (int) $employeeId : null;
    }

    private function employeeByEmailQuery(string $email): string
    {
        return sprintf(<<<'SQL'
            SELECT id, entityid, altname, email, isinactive, type FROM entity WHERE type = 'Employee' AND isinactive = 'F' AND lower(email) = lower('%s') ORDER BY id
        SQL, $this->escapeSqlString($email));
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
