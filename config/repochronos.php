<?php

return [
    'github_token' => env('GITHUB_TOKEN'),
    'github_ca' => env('GITHUB_CA_BUNDLE'),
    'python' => env('PYTHON_BINARY', 'python'),
    'threshold' => (int) env('COMPLEXITY_THRESHOLD', 5),
    'demo_email' => env('DEMO_EMAIL'),
    'demo_password' => env('DEMO_PASSWORD'),
    'demo_viewer_email' => env('DEMO_VIEWER_EMAIL'),
    'demo_viewer_password' => env('DEMO_VIEWER_PASSWORD'),
];
