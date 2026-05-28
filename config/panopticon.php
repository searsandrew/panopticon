<?php

return [
    'default_timezone' => env('PANOPTICON_DEFAULT_TIMEZONE', 'America/Chicago'),
    'contact_due_warning_days' => (int) env('PANOPTICON_CONTACT_DUE_WARNING_DAYS', 2),
];
