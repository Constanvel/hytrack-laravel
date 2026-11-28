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

    // Run `hytrack:upgrade` (migrations + missing demo room accounts) on each new instance.
    // On by default in api/index.php, the Vercel entry, which has no deploy-time migration step.
    'auto_migrate' => (bool) env('HYTRACK_AUTO_MIGRATE', false),

    // Password of the accounts created by the demo seeder.
    'demo_password' => env('HYTRACK_DEMO_PASSWORD', 'hytrack-demo'),

    // Cache store holding sign-ups waiting for their SMS code (must persist between requests).
    'signup_store' => env('HYTRACK_SIGNUP_STORE'),

    // Booking rules.
    'daily_quota' => (int) env('HYTRACK_DAILY_QUOTA', 30), // tickets per doctor per service date
    'booking_days' => (int) env('HYTRACK_BOOKING_DAYS', 60), // bookable service dates, today included
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
        'radiology' => 'Radiologi A-112 · Gedung A, lantai 1',
    ],

    // Counter where medicine is handed over (staff pharmacy screens, Figma 47:2 frames 36–37).
    'pharmacy_counter' => 'Loket F2',

    // Support services a doctor can refer to (Figma 47:2 frame 33), key => work unit name.
    'support_units' => [
        'laboratorium' => 'Laboratorium',
        'radiologi' => 'Radiologi',
    ],

    // Examination results uploaded by support staff: private disk, never a public URL.
    'support_result_disk' => env('HYTRACK_SUPPORT_RESULT_DISK', 'local'),
    'support_result_max_kb' => 5120,
];
