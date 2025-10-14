<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileController;

Route::get('/files', [FileController::class, 'index']);

// Regular upload (for smaller files)
Route::post('/files/upload', [FileController::class, 'upload']);

// Chunked upload endpoints
Route::post('/files/chunked/init', [FileController::class, 'initChunkedUpload']);
Route::post('/files/chunked/upload', [FileController::class, 'uploadChunk']);
Route::post('/files/chunked/finalize', [FileController::class, 'finalizeChunkedUpload']);
Route::post('/files/chunked/abort', [FileController::class, 'abortChunkedUpload']);

// Zip jobs
Route::post('/files/zip-jobs', [FileController::class, 'createZipJob']);
Route::get('/files/zip-jobs/{zipJob}', [FileController::class, 'showZipJob']);