<?php
header('Content-Type: text/plain');
print_r([
    'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? null,
    'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? null,
]);
