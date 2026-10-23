<?php

return [
    // Bump this string whenever terms.md/privacy.md content changes meaningfully;
    // existing users keep their old terms_version on record, not silently upgraded.
    'terms_version' => env('LEGAL_TERMS_VERSION', '1.0'),
];
