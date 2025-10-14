import { ref } from 'vue';
import {
  initChunkedUpload,
  uploadChunk,
  finalizeChunkedUpload,
  abortChunkedUpload,
  type DocumentRecord,
} from '@/api/files';

export interface UploadProgress {
  filename: string;
  progress: number;
  status: 'uploading' | 'finalizing' | 'completed' | 'error';
  currentChunk: number;
  totalChunks: number;
  error?: string;
  statusText?: string;
}

export function useChunkedUpload() {
  const uploading = ref(false);
  const uploadProgress = ref<Map<string, UploadProgress>>(new Map());
  const abortControllers = ref<Map<string, boolean>>(new Map());

  const CHUNK_SIZE = 10 * 1024 * 1024; // 10MB chunks

  /**
   * Upload a single file using chunked upload
   */
  async function uploadFile(file: File, folder = 'uploads'): Promise<DocumentRecord> {
    const fileId = `${file.name}_${Date.now()}`;
    
    // Initialize progress tracking
    uploadProgress.value.set(fileId, {
      filename: file.name,
      progress: 0,
      status: 'uploading',
      currentChunk: 0,
      totalChunks: 0,
    });

    abortControllers.value.set(fileId, false);

    console.log('[ChunkedUpload] Starting upload', {
      filename: file.name,
      size: file.size,
      chunk_size: CHUNK_SIZE,
    });

    try {
      // Calculate total chunks
      const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
      
      const progress = uploadProgress.value.get(fileId)!;
      progress.totalChunks = totalChunks;

      // Step 1: Initialize upload
      const { data: initData } = await initChunkedUpload({
        filename: file.name,
        total_size: file.size,
        total_chunks: totalChunks,
        folder,
      });

      const uploadId = initData.upload_id;
      console.log('[ChunkedUpload] Initialized', { upload_id: uploadId });

      // Step 2: Upload chunks
      for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        // Check if upload was aborted
        if (abortControllers.value.get(fileId)) {
          console.log('[ChunkedUpload] Aborted by user');
          await abortChunkedUpload(uploadId);
          throw new Error('Upload cancelled by user');
        }

        const start = chunkIndex * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, file.size);
        const chunk = file.slice(start, end);

        console.log('[ChunkedUpload] Uploading chunk', {
          chunk_index: chunkIndex + 1,
          total_chunks: totalChunks,
        });

        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', chunkIndex.toString());
        formData.append('chunk', chunk);

        await uploadChunk(formData);

        // Update progress
        progress.currentChunk = chunkIndex + 1;
        progress.progress = Math.round(((chunkIndex + 1) / totalChunks) * 100);
      }

      // Step 3: Finalize upload
      console.log('[ChunkedUpload] Finalizing');
      
      // Update progress to show finalization
      progress.status = 'uploading';
      progress.currentChunk = totalChunks;
      progress.progress = 99; // Show we're almost done but still processing
      
      const { data: finalData } = await finalizeChunkedUpload(uploadId);

      // Mark as completed
      progress.status = 'completed';
      progress.progress = 100;

      console.log('[ChunkedUpload] Completed', {
        document_id: finalData.document.id,
      });

      return finalData.document;

    } catch (error: any) {
      const progress = uploadProgress.value.get(fileId);
      if (progress) {
        progress.status = 'error';
        progress.error = error.message || 'Upload failed';
      }

      console.error('[ChunkedUpload] Failed', {
        filename: file.name,
        error: error.message,
      });

      throw error;
    } finally {
      abortControllers.value.delete(fileId);
    }
  }

  /**
   * Upload multiple files sequentially
   */
  async function uploadFiles(files: File[], folder = 'uploads'): Promise<DocumentRecord[]> {
    uploading.value = true;
    uploadProgress.value.clear();
    
    const documents: DocumentRecord[] = [];

    try {
      for (const file of files) {
        const document = await uploadFile(file, folder);
        documents.push(document);
      }

      return documents;
    } finally {
      uploading.value = false;
    }
  }

  /**
   * Abort all active uploads
   */
  function abortAll() {
    abortControllers.value.forEach((_, fileId) => {
      abortControllers.value.set(fileId, true);
    });
  }

  /**
   * Clear progress tracking
   */
  function clearProgress() {
    uploadProgress.value.clear();
  }

  return {
    uploading,
    uploadProgress,
    uploadFile,
    uploadFiles,
    abortAll,
    clearProgress,
  };
}

/**
 * Helper function to format bytes
 */
export function formatBytes(bytes: number, decimals = 2): string {
  if (bytes === 0) return '0 Bytes';

  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];

  const i = Math.floor(Math.log(bytes) / Math.log(k));

  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}