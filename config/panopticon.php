<?php

return [
    'admin' => env('PANOPTICON_ADMIN_EMAIL', []),
    'default_timezone' => env('PANOPTICON_DEFAULT_TIMEZONE', 'America/Chicago'),
    'contact_due_warning_days' => (int) env('PANOPTICON_CONTACT_DUE_WARNING_DAYS', 2),
    'netsuite_managed_sales_reps_field_id' => env('NETSUITE_MANAGED_SALES_REPS_FIELD_ID', 'custentity_managed_sales_reps'),
    'netsuite_managed_sales_reps_map_table' => env('NETSUITE_MANAGED_SALES_REPS_MAP_TABLE'),
];
