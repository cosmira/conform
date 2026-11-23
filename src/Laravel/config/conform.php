<?php

return [
    'path' => resource_path('openapi'),
    'enabled' => (bool) env('APP_DEBUG', false),
    'strict' => false,
];
