<?php
namespace App\Jobs;

use App\Models\Document;
use App\Models\ZipJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipStream\ZipStream;
use ZipStream\CompressionMethod;

class CreateZipArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200;
    protected string $zipJobId;

    public function __construct(string $zipJobId)
    {
        $this->zipJobId = $zipJobId;
        $this->onQueue('zip-jobs');
        $this->onConnection('database');
    }

    public function handle(): void
    {
        Log::info('[CreateZipArchive] Job started', ['zip_job_id' => $this->zipJobId]);

        $job = ZipJob::find($this->zipJobId);
        if (!$job) {
            Log::warning('[CreateZipArchive] job not found', ['zip_job_id' => $this->zipJobId]);
            return;
        }

        $job->forceFill([
            'status' => 'processing',
            'progress' => 5,
            'error' => null,
        ])->save();

        Log::info('[CreateZipArchive] Status updated to processing', ['zip_job_id' => $this->zipJobId]);

        $documents = Document::query()
            ->whereIn('id', $job->document_ids ?? [])
            ->get();
        
        Log::info('[CreateZipArchive] Documents collected', [
            'zip_job_id' => $this->zipJobId,
            'count' => $documents->count(),
            'document_ids' => $documents->pluck('id')->toArray()
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
            'archive_disk' => $archiveDiskName
        ]);

        $sourceDisk = Storage::disk($sourceDiskName);
        $archiveDisk = Storage::disk($archiveDiskName);

        $archivePrefix = trim(config('queuework.archive_prefix', 'archives'), '/');
        $archiveFilename = 'queuework_' . now()->format('Ymd_His') . '.zip';
        $archivePath = $archivePrefix . '/' . now()->format('Y/m/d') . '/' . Str::uuid() . '.zip';

        Log::info('[CreateZipArchive] Archive paths prepared', [
            'zip_job_id' => $this->zipJobId,
            'archive_path' => $archivePath,
            'archive_filename' => $archiveFilename
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
            $zip = new ZipStream(
                outputName: null,
                sendHttpHeaders: false,
                outputStream: $tempHandle
            );

            $total = $documents->count();
            $processed = 0;

            Log::info('[CreateZipArchive] Starting to add files to zip', [
                'zip_job_id' => $this->zipJobId,
                'total_files' => $total
            ]);

            foreach ($documents as $document) {
                $stream = $sourceDisk->readStream($document->path);
                if ($stream === false) {
                    Log::warning('[CreateZipArchive] missing file', [
                        'zip_job_id' => $job->id,
                        'path' => $document->path,
                        'disk' => $sourceDiskName,
                    ]);
                    continue;
                }

                $entryName = $document->original_name ?? basename($document->path);

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
                $processed++;
                $progress = (int) round(($processed / max($total, 1)) * 90) + 5;
                $job->forceFill(['progress' => min($progress, 95)])->save();

                Log::info('[CreateZipArchive] File added successfully', [
                    'zip_job_id' => $this->zipJobId,
                    'processed' => $processed,
                    'total' => $total,
                    'progress' => min($progress, 95) . '%'
                ]);
            }

            Log::info('[CreateZipArchive] Finishing zip stream', ['zip_job_id' => $this->zipJobId]);

            $zip->finish();
            fflush($tempHandle);
            fclose($tempHandle);

            Log::info('[CreateZipArchive] Zip created, preparing upload', [
                'zip_job_id' => $this->zipJobId,
                'temp_file' => $tempFilePath
            ]);

            $readStream = fopen($tempFilePath, 'rb');
            if ($readStream === false) {
                throw new \RuntimeException('Unable to reopen temporary archive for upload.');
            }

            Log::info('[CreateZipArchive] Uploading to storage', [
                'zip_job_id' => $this->zipJobId,
                'archive_path' => $archivePath,
                'disk' => $archiveDiskName
            ]);

            $success = $archiveDisk->put($archivePath, $readStream);
            fclose($readStream);

            if (!$success) {
                throw new \RuntimeException('Failed to upload archive to storage.');
            }

            Log::info('[CreateZipArchive] Upload successful', [
                'zip_job_id' => $this->zipJobId,
                'archive_path' => $archivePath
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
    }
}