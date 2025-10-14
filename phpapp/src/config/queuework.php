<?php

return [
    'archive_prefix' => env('ZIP_ARCHIVE_PREFIX', 'queuework/archives'),
    'archive_disk' => env('ARCHIVE_DISK', env('FILESYSTEM_DISK', 's3')),
    'zip_download_ttl' => (int) env('ZIP_DOWNLOAD_TTL', 15),
];
