<?php

return [
    // Shared by the Phil scraper (Panther) and the PDF export (Browsershot).
    'binary' => env('CHROME_PATH'),
    'driver' => env('CHROMEDRIVER_PATH'),
];
