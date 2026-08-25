<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingestion
    |--------------------------------------------------------------------------
    |
    | Cameras reach us three ways: an outbound HTTP webhook, a long-lived ISAPI
    | alert stream per camera, and a sweep of the directory cameras FTP their
    | captures into. The sweep is a reliability fallback.
    |
    | The default drop path matches the `hik-drop` volume mounted at
    | `storage/app/private/hikvision-drop` by docker-compose. Overriding via
    | `TRAFFICFLOW_PLATE_DROP_PATH` is only necessary when the deployment mounts
    | the FTP directory somewhere else.
    |
    */

    'plate_drop_path' => env('TRAFFICFLOW_PLATE_DROP_PATH') ?: storage_path('app/private/hikvision-drop'),

    // Two captures of the same plate on the same camera inside this window are
    // treated as one event.
    'dedupe_window_seconds' => (int) env('TRAFFICFLOW_DEDUPE_WINDOW_SECONDS', 20),

    // Correct single-character OCR misreads against plates already on site.
    'fuzzy_match_enabled' => (bool) env('TRAFFICFLOW_FUZZY_MATCH_ENABLED', true),

    // Only consider a fuzzy correction for plates of at least this length,
    // since short strings are one edit away from far too much.
    'fuzzy_match_min_length' => 5,

    'alert_stream' => [
        'timeout' => (int) env('TRAFFICFLOW_ALERT_STREAM_TIMEOUT', 0),
        'connect_timeout' => 10,
        'retry_base_seconds' => (int) env('TRAFFICFLOW_ALERT_STREAM_RETRY_BASE', 5),
        'retry_max_seconds' => (int) env('TRAFFICFLOW_ALERT_STREAM_RETRY_MAX', 300),
    ],

    // A camera silent for longer than this shows as unreachable.
    'camera_stale_after_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Visit matching
    |--------------------------------------------------------------------------
    */

    // An open visit older than this never got an exit event.
    'orphan_after_hours' => (int) env('TRAFFICFLOW_ORPHAN_AFTER_HOURS', 12),

    // Default dwell threshold for the Security view.
    'dwell_alert_hours' => (int) env('TRAFFICFLOW_DWELL_ALERT_HOURS', 4),

    // Selectable thresholds on the Security page.
    'dwell_alert_options' => [3, 4, 6],

    /*
    |--------------------------------------------------------------------------
    | Recurring (staff) plate detection
    |--------------------------------------------------------------------------
    |
    | Plates that show up on most weekdays at a consistent time are staff or
    | tenants, not shoppers. We tag the plate only: no name, no profile.
    |
    */

    'recurring_window_days' => (int) env('TRAFFICFLOW_RECURRING_WINDOW_DAYS', 28),
    'recurring_min_weekday_ratio' => (float) env('TRAFFICFLOW_RECURRING_MIN_WEEKDAY_RATIO', 0.8),
    'recurring_max_arrival_stddev_minutes' => (float) env('TRAFFICFLOW_RECURRING_MAX_ARRIVAL_STDDEV_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Security heuristics
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Hours considered "odd" for a recurring visit.
        'odd_hours' => ['start' => 22, 'end' => 5],
        'odd_hour_window_days' => 14,
        'multi_entry_threshold' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | POPIA retention
    |--------------------------------------------------------------------------
    |
    | Plate numbers are personal data. PrunePlateData deletes events and visits
    | past the retention window; sites may shorten this in Settings.
    |
    */

    'retention_days' => (int) env('TRAFFICFLOW_RETENTION_DAYS', 365),
    'retention_min_days' => 30,
    'retention_max_days' => 1095,

    /*
    |--------------------------------------------------------------------------
    | Scheduled reports
    |--------------------------------------------------------------------------
    |
    | Sites may have their traffic report emailed to a list of addresses. The
    | export carries aggregates only; plate numbers never leave the app.
    |
    */

    'report_schedule' => env('TRAFFICFLOW_REPORT_SCHEDULE', 'off'),
    'report_send_hour' => (int) env('TRAFFICFLOW_REPORT_SEND_HOUR', 6),
    'report_max_recipients' => 10,

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */

    'currency' => env('TRAFFICFLOW_CURRENCY', 'ZAR'),

    // Charged per camera, per paying shop sub-user, per month.
    'variable_rate_per_camera_per_subuser' => (float) env('TRAFFICFLOW_VARIABLE_RATE_PER_CAMERA_PER_SUBUSER', 20.00),

    // Suggested range an owner picks from when inviting a shop.
    'shop_monthly_amount_default' => 400.00,
    'shop_monthly_amount_min' => 350.00,
    'shop_monthly_amount_max' => 500.00,

    // Share of shop revenue the platform retains by default.
    'platform_shop_revenue_share' => 0.30,

    'partner_commission_rate' => (float) env('TRAFFICFLOW_PARTNER_COMMISSION_RATE', 0.20),

    'shop_invitation_expires_days' => 14,

    // Flat monthly rate for a Security Operator seat. Same rate as a shop
    // sub-user by default because both consume a login and a seat on the
    // owner's invoice, and the operator's job is not less valuable than
    // that of a shop admin.
    'security_operator_monthly_amount' => (float) env('TRAFFICFLOW_SECURITY_OPERATOR_MONTHLY_AMOUNT', 20.00),
    'security_operator_invite_expires_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | Support
    |--------------------------------------------------------------------------
    |
    | Shown to tenants who are locked out by the paywall and need a human.
    |
    */

    'billing_email' => env('TRAFFICFLOW_BILLING_EMAIL', 'billing@centrevision.co.za'),
    'support_email' => env('TRAFFICFLOW_SUPPORT_EMAIL', 'support@centrevision.co.za'),

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | When true, the login screen shows seeded demo accounts with one-click
    | prefill. Off in production; on locally so people can try each role.
    |
    */

    'demo_mode' => (bool) env('TRAFFICFLOW_DEMO_MODE', env('APP_ENV', 'production') !== 'production'),

];
