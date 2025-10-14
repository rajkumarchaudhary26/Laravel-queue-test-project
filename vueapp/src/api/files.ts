import http from './http';

export interface DocumentRecord {
  id: number;
  disk: string;
  path: string;
  original_name: string;
  extension: string | null;
  size: number | null;
  mime_type: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedDocuments {
  data: DocumentRecord[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface CreateZipJobResponse {
  job_id: string;
  status: string;
  progress: number;
}

export interface ZipJobStatusResponse {
  job_id: string;
  status: 'queued' | 'processing' | 'completed' | 'failed';
  progress: number | null;
  error?: string | null;
  download_url?: string | null;
  expires_in_minutes?: number;
}

// Chunked upload interfaces
export interface InitChunkedUploadRequest {
  filename: string;
  total_size: number;
  total_chunks: number;
  folder?: string;
}

export interface InitChunkedUploadResponse {
  upload_id: string;
  chunk_size: number;
}

export interface UploadChunkResponse {
  message: string;
  uploaded_chunks: number;
  total_chunks: number;
  complete: boolean;
}

export interface FinalizeChunkedUploadResponse {
  message: string;
  document: DocumentRecord;
}

// List documents with pagination
export function listDocuments(page = 1) {
  return http.get<PaginatedDocuments>('/files', {
    params: { page },
  });
}

// Chunked upload: Initialize
export function initChunkedUpload(data: InitChunkedUploadRequest) {
  return http.post<InitChunkedUploadResponse>('/files/chunked/init', data);
}

// Chunked upload: Upload a chunk
export function uploadChunk(formData: FormData) {
  return http.post<UploadChunkResponse>('/files/chunked/upload', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
}

// Chunked upload: Finalize
export function finalizeChunkedUpload(uploadId: string) {
  return http.post<FinalizeChunkedUploadResponse>('/files/chunked/finalize', {
    upload_id: uploadId,
  });
}

// Chunked upload: Abort
export function abortChunkedUpload(uploadId: string) {
  return http.post('/files/chunked/abort', {
    upload_id: uploadId,
  });
}

// Zip job operations
export function createZipJob(documentIds: number[]) {
  return http.post<CreateZipJobResponse>('/files/zip-jobs', {
    document_ids: documentIds,
  });
}

export function getZipJobStatus(jobId: string) {
  return http.get<ZipJobStatusResponse>(`/files/zip-jobs/${jobId}`);
}
