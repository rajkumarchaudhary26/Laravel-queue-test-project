<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\ZipJob;
use Aws\S3\S3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

class CreateZipArchive implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK_SIZE = 1024 * 1024; // 1MB chunks
    
    public int $tries = 3;
    public int $timeout = 1800; // 30 minutes for large files
    public int $maxExceptions = 3;
    
    // Prevent job overlap for 2 hours
    public int $uniqueFor = 7200;

    private ?S3Client $s3Client = null;
    private string $zipJobId;

    public function __construct(string $zipJobId)
    {
        $this->zipJobId = $zipJobId;
        $this->onQueue('zip-jobs');
        $this->onConnection(config('queue.default'));
    }

    /**
     * The unique ID of the job.
     * This ensures only ONE worker processes this specific zip job at a time.
     */
    public function uniqueId(): string
    {
        return "zip-job:{$this->zipJobId}";
    }

    /**
     * Get the cache driver that should manage the lock.
     */
    public function uniqueVia()
    {
        return \Illuminate\Support\Facades\Cache::driver('redis');
    }

    public function handle(): void
    {
        Log::info('[CreateZipArchive] Job started', [
            'zip_job_id' => $this->zipJobId,
            'worker_pid' => getmypid(),
            'attempt' => $this->attempts()
        ]);
        
        $job = ZipJob::find($this->zipJobId);
        if (!$job) {
            Log::warning('[CreateZipArchive] job not found', ['zip_job_id' => $this->zipJobId]);
            return;
        }

        // Check if job is already being processed or completed
        if (in_array($job->status, ['processing', 'completed'])) {
            Log::warning('[CreateZipArchive] Job already in progress or completed', [
                'zip_job_id' => $this->zipJobId,
                'status' => $job->status,
                'worker_pid' => getmypid()
            ]);
            return;
        }

        $job->forceFill([
            'status' => 'processing',
            'progress' => 5,
            'error' => null,
        ])->save();
        
        Log::info('[CreateZipArchive] Status updated to processing', [
            'zip_job_id' => $this->zipJobId,
            'worker_pid' => getmypid()
        ]);

        $documents = Document::query()
            ->whereIn('id', $job->document_ids ?? [])
            ->get();

        Log::info('[CreateZipArchive] Documents collected', [
            'zip_job_id' => $this->zipJobId,
            'count' => $documents->count(),
            'document_ids' => $documents->pluck('id')->toArray(),
            'worker_pid' => getmypid()
        ]);

        if ($documents->isEmpty()) {
            $job->forceFill([
                'status' => 'failed',
                'progress' => 0,
                'error' => 'No documents found for the requested job.',
            ])->save();
            return;
        }

        $sourceDiskName = $job->disk ?: config('filesystems.default');
        $archiveDiskName = $job->archive_disk ?: config('queuework.archive_disk', $sourceDiskName);

        Log::info('[CreateZipArchive] Disk configuration', [
            'zip_job_id' => $this->zipJobId,
            'source_disk' => $sourceDiskName,
            'archive_disk' => $archiveDiskName,
            'worker_pid' => getmypid()
        ]);

        // Initialize S3 client if using S3
        if ($sourceDiskName === 's3') {
            $this->initializeS3Client();
        }

        $sourceDisk = Storage::disk($sourceDiskName);
        $archiveDisk = Storage::disk($archiveDiskName);

        $archivePrefix = trim(config('queuework.archive_prefix', 'archives'), '/');
        $archiveFilename = 'queuework_' . now()->format('Ymd_His') . '.zip';
        $archivePath = $archivePrefix . '/' . now()->format('Y/m/d') . '/' . Str::uuid() . '.zip';

        Log::info('[CreateZipArchive] Archive paths prepared', [
            'zip_job_id' => $this->zipJobId,
            'archive_path' => $archivePath,
            'archive_filename' => $archiveFilename,
            'worker_pid' => getmypid()
        ]);

        $tempFilePath = tempnam(sys_get_temp_dir(), 'queuework_');
        if ($tempFilePath === false) {
            $job->forceFill([
                'status' => 'failed',
                'progress' => 0,
                'error' => 'Unable to allocate temporary storage for archive.',
            ])->save();
            return;
        }

        $tempHandle = fopen($tempFilePath, 'w+b');
        if ($tempHandle === false) {
            @unlink($tempFilePath);
            $job->forceFill([
                'status' => 'failed',
                'progress' => 0,
                'error' => 'Unable to open temporary archive handle.',
            ])->save();
            return;
        }

        try {
            Log::info('[CreateZipArchive] Creating ZipStream', [
                'zip_job_id' => $this->zipJobId,
                'worker_pid' => getmypid()
            ]);
            
            $zip = new ZipStream(
                outputName: null,
                sendHttpHeaders: false,
                outputStream: $tempHandle
            );

            $total = $documents->count();
            $processed = 0;

            Log::info('[CreateZipArchive] Starting to add files to zip', [
                'zip_job_id' => $this->zipJobId,
                'total_files' => $total,
                'worker_pid' => getmypid()
            ]);

            foreach ($documents as $index => $document) {
                Log::info('[CreateZipArchive] Processing file', [
                    'zip_job_id' => $this->zipJobId,
                    'document_id' => $document->id,
                    'path' => $document->path,
                    'original_name' => $document->original_name,
                    'progress' => ($index + 1) . '/' . $total,
                    'worker_pid' => getmypid()
                ]);

                $entryName = $document->original_name ?? basename($document->path);

                try {
                    // Use chunked streaming for S3 files
                    if ($sourceDiskName === 's3') {
                        $this->addS3FileToZipWithStreaming($zip, $entryName, $document->path);
                    } else {
                        // Fallback to regular stream for non-S3
                        $stream = $sourceDisk->readStream($document->path);
                        if ($stream === false) {
                            Log::warning('[CreateZipArchive] missing file', [
                                'zip_job_id' => $job->id,
                                'path' => $document->path,
                                'disk' => $sourceDiskName,
                            ]);
                            continue;
                        }

                        Log::info('[CreateZipArchive] Adding file to zip', [
                            'zip_job_id' => $this->zipJobId,
                            'entry_name' => $entryName
                        ]);

                        $zip->addFileFromStream(
                            fileName: $entryName,
                            stream: $stream,
                            compressionMethod: CompressionMethod::STORE
                        );

                        fclose($stream);
                    }

                    $processed++;
                    $progress = (int) round(($processed / max($total, 1)) * 90) + 5;
                    $job->forceFill(['progress' => min($progress, 95)])->save();

                    Log::info('[CreateZipArchive] File added successfully', [
                        'zip_job_id' => $this->zipJobId,
                        'processed' => $processed,
                        'total' => $total,
                        'progress' => min($progress, 95) . '%',
                        'worker_pid' => getmypid()
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[CreateZipArchive] Failed to add file to zip', [
                        'zip_job_id' => $this->zipJobId,
                        'document_id' => $document->id,
                        'path' => $document->path,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with other files
                    continue;
                }
            }

            Log::info('[CreateZipArchive] Finishing zip stream', [
                'zip_job_id' => $this->zipJobId,
                'worker_pid' => getmypid()
            ]);
            
            $zip->finish();
            fflush($tempHandle);
            fclose($tempHandle);

            Log::info('[CreateZipArchive] Zip created, preparing upload', [
                'zip_job_id' => $this->zipJobId,
                'temp_file' => $tempFilePath,
                'file_size' => filesize($tempFilePath),
                'worker_pid' => getmypid()
            ]);

            $readStream = fopen($tempFilePath, 'rb');
            if ($readStream === false) {
                throw new \RuntimeException('Unable to reopen temporary archive for upload.');
            }

            Log::info('[CreateZipArchive] Uploading to storage', [
                'zip_job_id' => $this->zipJobId,
                'archive_path' => $archivePath,
                'disk' => $archiveDiskName,
                'worker_pid' => getmypid()
            ]);

            $success = $archiveDisk->put($archivePath, $readStream);
            fclose($readStream);

            if (!$success) {
                throw new \RuntimeException('Failed to upload archive to storage.');
            }

            Log::info('[CreateZipArchive] Upload successful', [
                'zip_job_id' => $this->zipJobId,
                'archive_path' => $archivePath,
                'worker_pid' => getmypid()
            ]);

            $job->forceFill([
                'status' => 'completed',
                'progress' => 100,
                'result_path' => $archivePath,
                'result_filename' => $archiveFilename,
                'completed_at' => now(),
            ])->save();

            Log::info('[CreateZipArchive] archive ready', [
                'zip_job_id' => $job->id,
                'archive_path' => $archivePath,
                'disk' => $archiveDiskName,
                'worker_pid' => getmypid()
            ]);
        } catch (\Throwable $exception) {
            $job->forceFill([
                'status' => 'failed',
                'progress' => 0,
                'error' => $exception->getMessage(),
            ])->save();

            Log::error('[CreateZipArchive] failed', [
                'zip_job_id' => $job->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'worker_pid' => getmypid()
            ]);
        } finally {
            if (is_resource($tempHandle)) {
                fclose($tempHandle);
            }
            if (is_file($tempFilePath)) {
                @unlink($tempFilePath);
            }
        }
    }

    /**
     * Initialize S3 client
     */
    private function initializeS3Client(): void
    {
        $config = config('filesystems.disks.s3');
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
            'credentials' => [
                'key'    => $config['key'],
                'secret' => $config['secret'],
                'token'  => $config['token'] ?? null,
            ],
        ]);

        Log::info('[CreateZipArchive] S3 client initialized', [
            'zip_job_id' => $this->zipJobId,
            'region' => $config['region']
        ]);
    }

    /**
     * Add S3 file to zip using chunked streaming
     */
    private function addS3FileToZipWithStreaming($zip, string $entryName, string $s3Key): void
    {
        if (!$this->s3Client) {
            throw new \Exception('S3 client not initialized');
        }

        $config = config('filesystems.disks.s3');
        $bucket = $config['bucket'];

        // Get file size first
        try {
            $headResult = $this->s3Client->headObject([
                'Bucket' => $bucket,
                'Key' => $s3Key,
            ]);
            $fileSize = $headResult['ContentLength'] ?? 0;
        } catch (\Throwable $e) {
            Log::warning('[CreateZipArchive] Could not get file size, skipping', [
                'zip_job_id' => $this->zipJobId,
                'key' => $s3Key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }

        Log::info('[CreateZipArchive] Streaming S3 file in chunks', [
            'zip_job_id' => $this->zipJobId,
            'file' => $s3Key,
            'entry_name' => $entryName,
            'size' => $fileSize
        ]);

        $tempStream = tmpfile();
        $offset = 0;
        $streamClosed = false;

        try {
            // Download file in chunks
            while ($offset < $fileSize) {
                $chunkEnd = min($offset + self::CHUNK_SIZE - 1, $fileSize - 1);
                $range = "bytes={$offset}-{$chunkEnd}";

                Log::debug('[CreateZipArchive] Fetching S3 chunk', [
                    'zip_job_id' => $this->zipJobId,
                    'range' => $range,
                    'offset' => $offset,
                    'chunk_end' => $chunkEnd
                ]);

                try {
                    $result = $this->s3Client->getObject([
                        'Bucket' => $bucket,
                        'Key' => $s3Key,
                        'Range' => $range,
                    ]);

                    $chunkData = $result['Body']->getContents();
                    fwrite($tempStream, $chunkData);
                    $offset += strlen($chunkData);

                    // Log progress for large files
                    if ($fileSize > 10 * 1024 * 1024) { // > 10MB
                        $progress = round(($offset / $fileSize) * 100, 1);
                        Log::info('[CreateZipArchive] S3 streaming progress', [
                            'zip_job_id' => $this->zipJobId,
                            'file' => basename($s3Key),
                            'entry_name' => $entryName,
                            'progress' => "{$progress}%",
                            'bytes' => "{$offset}/{$fileSize}"
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('[CreateZipArchive] S3 chunk fetch failed', [
                        'zip_job_id' => $this->zipJobId,
                        'range' => $range,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }

            // Rewind and add to zip
            rewind($tempStream);

            Log::info('[CreateZipArchive] Adding file to zip', [
                'zip_job_id' => $this->zipJobId,
                'entry_name' => $entryName,
                'file_size' => $fileSize
            ]);

            $zip->addFileFromStream(
                fileName: $entryName,
                stream: $tempStream,
                compressionMethod: CompressionMethod::STORE
            );

            Log::info('[CreateZipArchive] S3 file added to zip successfully', [
                'zip_job_id' => $this->zipJobId,
                'file' => $s3Key,
                'entry' => $entryName
            ]);
        } finally {
            if (!$streamClosed && is_resource($tempStream)) {
                fclose($tempStream);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        $job = ZipJob::find($this->zipJobId);
        if (!$job) {
            return;
        }

        $job->forceFill([
            'status' => 'failed',
            'progress' => 0,
            'error' => $exception->getMessage(),
        ])->save();

        Log::error('[CreateZipArchive] Job failed', [
            'zip_job_id' => $this->zipJobId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'worker_pid' => getmypid()
        ]);
    }
}
