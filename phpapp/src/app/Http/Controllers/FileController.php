<?php
namespace App\Http\Controllers;

use App\Jobs\CreateZipArchive;
use App\Models\Document;
use App\Models\ZipJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = Document::latest()->paginate(25);
        return response()->json($documents);
    }

    // Legacy endpoint removed - use chunked upload for all files

    /**
     * Initialize a chunked upload session
     */
    public function initChunkedUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'total_size' => ['required', 'integer', 'min:1'],
            'total_chunks' => ['required', 'integer', 'min:1'],
            'folder' => ['nullable', 'string'],
        ]);

        Log::info('[FileController.initChunkedUpload] initializing', [
            'filename' => $data['filename'],
            'total_size' => $data['total_size'],
            'total_chunks' => $data['total_chunks'],
        ]);

        $diskName = config('filesystems.default', 's3');
        if ($diskName !== 's3') {
            throw ValidationException::withMessages([
                'files' => ['FILESYSTEM_DISK must be set to s3 for this sample project.'],
            ]);
        }

        $folder = trim($data['folder'] ?? 'uploads', '/');
        if ($folder === '' || str_contains($folder, '..')) {
            $folder = 'uploads';
        }

        // Generate unique upload ID
        $uploadId = Str::uuid()->toString();
        
        // Store upload metadata in cache (valid for 24 hours)
        $metadata = [
            'upload_id' => $uploadId,
            'filename' => $data['filename'],
            'total_size' => $data['total_size'],
            'total_chunks' => $data['total_chunks'],
            'folder' => $folder,
            'disk' => $diskName,
            'uploaded_chunks' => [],
            'temp_path' => "temp-uploads/{$uploadId}",
            'created_at' => now()->toIso8601String(),
        ];

        cache()->put("chunked_upload:{$uploadId}", $metadata, now()->addHours(24));

        Log::info('[FileController.initChunkedUpload] session created', [
            'upload_id' => $uploadId,
            'metadata' => $metadata,
        ]);

        return response()->json([
            'upload_id' => $uploadId,
            'chunk_size' => 10 * 1024 * 1024, // 10MB recommended
        ], 201);
    }

    /**
     * Upload a single chunk
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $uploadId = $data['upload_id'];
        $chunkIndex = $data['chunk_index'];

        Log::info('[FileController.uploadChunk] receiving chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
        ]);

        // Retrieve upload metadata
        $metadata = cache()->get("chunked_upload:{$uploadId}");
        if (!$metadata) {
            return response()->json([
                'message' => 'Upload session not found or expired.',
            ], 404);
        }

        // Validate chunk index
        if ($chunkIndex >= $metadata['total_chunks']) {
            return response()->json([
                'message' => 'Invalid chunk index.',
            ], 422);
        }

        // Check if chunk already uploaded
        if (in_array($chunkIndex, $metadata['uploaded_chunks'])) {
            Log::info('[FileController.uploadChunk] chunk already uploaded', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
            ]);

            return response()->json([
                'message' => 'Chunk already uploaded.',
                'uploaded_chunks' => count($metadata['uploaded_chunks']),
                'total_chunks' => $metadata['total_chunks'],
            ], 200);
        }

        $disk = Storage::disk($metadata['disk']);
        $chunkFile = $request->file('chunk');
        
        // Store chunk temporarily
        $chunkPath = "{$metadata['temp_path']}/chunk_{$chunkIndex}";
        $disk->put($chunkPath, file_get_contents($chunkFile->getRealPath()));

        // Update metadata
        $metadata['uploaded_chunks'][] = $chunkIndex;
        sort($metadata['uploaded_chunks']);
        cache()->put("chunked_upload:{$uploadId}", $metadata, now()->addHours(24));

        Log::info('[FileController.uploadChunk] chunk stored', [
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'chunk_path' => $chunkPath,
            'progress' => count($metadata['uploaded_chunks']) . '/' . $metadata['total_chunks'],
        ]);

        return response()->json([
            'message' => 'Chunk uploaded successfully.',
            'uploaded_chunks' => count($metadata['uploaded_chunks']),
            'total_chunks' => $metadata['total_chunks'],
            'complete' => count($metadata['uploaded_chunks']) === $metadata['total_chunks'],
        ], 200);
    }

    /**
     * Finalize chunked upload - combine all chunks
     */
    public function finalizeChunkedUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $uploadId = $data['upload_id'];

        Log::info('[FileController.finalizeChunkedUpload] finalizing', [
            'upload_id' => $uploadId,
        ]);

        // Retrieve upload metadata
        $metadata = cache()->get("chunked_upload:{$uploadId}");
        if (!$metadata) {
            return response()->json([
                'message' => 'Upload session not found or expired.',
            ], 404);
        }

        // Verify all chunks uploaded
        if (count($metadata['uploaded_chunks']) !== $metadata['total_chunks']) {
            return response()->json([
                'message' => 'Not all chunks have been uploaded.',
                'uploaded_chunks' => count($metadata['uploaded_chunks']),
                'total_chunks' => $metadata['total_chunks'],
            ], 422);
        }

        $disk = Storage::disk($metadata['disk']);

        // Create a temporary local file to combine chunks
        $tempLocalFile = tempnam(sys_get_temp_dir(), 'chunked_upload_');
        $tempHandle = fopen($tempLocalFile, 'wb');

        if (!$tempHandle) {
            Log::error('[FileController.finalizeChunkedUpload] failed to create temp file', [
                'upload_id' => $uploadId,
            ]);

            return response()->json([
                'message' => 'Failed to process upload.',
            ], 500);
        }

        try {
            // Combine all chunks in order
            for ($i = 0; $i < $metadata['total_chunks']; $i++) {
                $chunkPath = "{$metadata['temp_path']}/chunk_{$i}";
                
                if (!$disk->exists($chunkPath)) {
                    throw new \RuntimeException("Chunk {$i} not found.");
                }

                $chunkContent = $disk->get($chunkPath);
                fwrite($tempHandle, $chunkContent);

                Log::info('[FileController.finalizeChunkedUpload] combined chunk', [
                    'upload_id' => $uploadId,
                    'chunk_index' => $i,
                ]);
            }

            fclose($tempHandle);

            // Upload combined file to final destination
            $extension = pathinfo($metadata['filename'], PATHINFO_EXTENSION);
            $finalPath = $metadata['folder'] . '/' . Str::uuid() . '_' . $metadata['filename'];
            
            $finalStream = fopen($tempLocalFile, 'rb');
            $disk->put($finalPath, $finalStream);
            fclose($finalStream);

            // Get file info
            $fileSize = filesize($tempLocalFile);
            $mimeType = mime_content_type($tempLocalFile) ?: 'application/octet-stream';

            // Clean up temporary local file
            @unlink($tempLocalFile);

            // Clean up chunks from S3
            for ($i = 0; $i < $metadata['total_chunks']; $i++) {
                $chunkPath = "{$metadata['temp_path']}/chunk_{$i}";
                $disk->delete($chunkPath);
            }

            // Create document record
            $document = Document::create([
                'disk' => $metadata['disk'],
                'path' => $finalPath,
                'original_name' => $metadata['filename'],
                'extension' => $extension ?: null,
                'size' => $fileSize,
                'mime_type' => $mimeType,
            ]);

            // Clear cache
            cache()->forget("chunked_upload:{$uploadId}");

            Log::info('[FileController.finalizeChunkedUpload] upload completed', [
                'upload_id' => $uploadId,
                'document_id' => $document->id,
                'path' => $finalPath,
                'size' => $fileSize,
            ]);

            return response()->json([
                'message' => 'File uploaded successfully.',
                'document' => $document,
            ], 201);

        } catch (\Throwable $e) {
            if (is_resource($tempHandle)) {
                fclose($tempHandle);
            }
            @unlink($tempLocalFile);

            Log::error('[FileController.finalizeChunkedUpload] failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to finalize upload: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel/abort a chunked upload
     */
    public function abortChunkedUpload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'string'],
        ]);

        $uploadId = $data['upload_id'];

        Log::info('[FileController.abortChunkedUpload] aborting', [
            'upload_id' => $uploadId,
        ]);

        $metadata = cache()->get("chunked_upload:{$uploadId}");
        if (!$metadata) {
            return response()->json([
                'message' => 'Upload session not found.',
            ], 404);
        }

        // Clean up chunks
        $disk = Storage::disk($metadata['disk']);
        foreach ($metadata['uploaded_chunks'] as $chunkIndex) {
            $chunkPath = "{$metadata['temp_path']}/chunk_{$chunkIndex}";
            $disk->delete($chunkPath);
        }

        // Clear cache
        cache()->forget("chunked_upload:{$uploadId}");

        Log::info('[FileController.abortChunkedUpload] aborted', [
            'upload_id' => $uploadId,
        ]);

        return response()->json([
            'message' => 'Upload aborted successfully.',
        ], 200);
    }

    public function createZipJob(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'exists:documents,id'],
        ]);

        $documentIds = array_values(array_unique($data['document_ids']));

        Log::info('[FileController.createZipJob] request received', [
            'document_ids' => $documentIds,
        ]);

        $documents = Document::whereIn('id', $documentIds)->get();

        if ($documents->count() !== count($documentIds)) {
            return response()->json([
                'message' => 'One or more documents could not be found.',
            ], 404);
        }

        $diskName = $documents->pluck('disk')->unique()->count() === 1
            ? $documents->first()->disk
            : null;

        if ($diskName !== 's3') {
            return response()->json([
                'message' => 'All documents must exist on the s3 disk.',
            ], 422);
        }

        $zipJob = ZipJob::create([
            'document_ids' => $documentIds,
            'disk' => $diskName,
            'archive_disk' => config('queuework.archive_disk', $diskName),
            'status' => 'queued',
            'progress' => 0,
        ]);

        CreateZipArchive::dispatch($zipJob->id)->onQueue('zip-jobs');

        Log::info('[FileController.createZipJob] queued job', [
            'zip_job_id' => $zipJob->id,
            'document_ids' => $documentIds,
        ]);

        return response()->json([
            'job_id' => $zipJob->id,
            'status' => $zipJob->status,
            'progress' => $zipJob->progress,
        ], 202);
    }

    public function showZipJob(string $zipJobId): JsonResponse
    {
        $zipJob = ZipJob::find($zipJobId);

        if (!$zipJob) {
            Log::warning('[FileController.showZipJob] job not found', [
                'zip_job_id' => $zipJobId,
            ]);
            return response()->json(['message' => 'Job not found.'], 404);
        }

        $response = [
            'job_id' => $zipJob->id,
            'status' => $zipJob->status,
            'progress' => $zipJob->progress,
            'error' => $zipJob->error,
        ];

        if ($zipJob->status === 'completed' && $zipJob->result_path) {
            $disk = Storage::disk($zipJob->archive_disk ?: config('queuework.archive_disk', $zipJob->disk));
            $ttlMinutes = (int) config('queuework.zip_download_ttl', 15);
            $filename = $zipJob->result_filename ?? basename($zipJob->result_path);

            $response['download_url'] = $disk->temporaryUrl(
                $zipJob->result_path,
                now()->addMinutes($ttlMinutes),
                [
                    'ResponseContentDisposition' => 'attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
                    'ResponseContentType' => 'application/zip',
                ]
            );
            $response['expires_in_minutes'] = $ttlMinutes;
        }

        return response()->json($response);
    }
}
