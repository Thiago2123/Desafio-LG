<?php

return [
    'enabled' => env('SIMULATION_ENABLED', true),
    'date' => env('SIMULATION_DATE', '2026-02-01'),
    'interval_seconds' => (int) env('SIMULATION_INTERVAL_SECONDS', 10),
];
