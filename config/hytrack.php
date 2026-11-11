<?php

return [
    'hospital' => env('HYTRACK_HOSPITAL', 'RS Harapan Sehat'),

    // Hospital-local zone. Datetimes are stored in UTC; service_date is a local date.
    'timezone' => env('HYTRACK_TIMEZONE', 'Asia/Jakarta'),

    /*
     * Lets a patient account simulate staff actions (check-in, examination done, ...)
     * from the patient screens so the whole journey can be demonstrated with one login.
     * Must stay off wherever real patients use the app.
     */
    'demo_controls' => (bool) env('HYTRACK_DEMO_CONTROLS', false),

    // Password of the accounts created by the demo seeder.
    'demo_password' => env('HYTRACK_DEMO_PASSWORD', 'hytrack-demo'),

    // Booking rules.
    'daily_quota' => (int) env('HYTRACK_DAILY_QUOTA', 30), // tickets per doctor per service date
    'booking_days' => 3,                                   // today plus the next two days
    'check_in_lead_minutes' => 15,                         // check in this long before practice starts

    // Time to Go: assumed travel time. The patient's real route is not known yet.
    'travel_minutes' => (int) env('HYTRACK_TRAVEL_MINUTES', 25),

    // Smart Wait Time.
    'default_service_minutes' => 5, // used until a doctor has finished consultations that day
    'wait_range_minutes' => 10,     // estimates are shown as a range: "15–25 mnt"
    'wait_target_minutes' => 30,    // service target used by the management insights

    // Seconds between automatic refreshes of live queue screens.
    'poll_seconds' => (int) env('HYTRACK_POLL_SECONDS', 15),

    // Fixed service points, used for journey stages and directions.
    'rooms' => [
        'check_in' => 'Meja check-in · Gedung A, lantai 2',
        'laboratory' => 'Laboratorium A-108 · Gedung A, lantai 1',
        'pharmacy' => 'Farmasi Loket F2 · Gedung A, lantai 1',
    ],
];
