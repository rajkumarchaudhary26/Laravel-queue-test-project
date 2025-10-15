<template>
  <div class="min-h-screen bg-slate-950 text-slate-100">
    <header class="border-b border-slate-800 bg-slate-900">
      <div class="mx-auto flex max-w-4xl flex-col gap-3 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 class="text-2xl font-semibold">QueueWork File Console</h1>
          <p class="mt-1 text-sm text-slate-400">
            Upload files to S3 and orchestrate asynchronous zip downloads through the queue.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="inline-flex items-center rounded-full border border-emerald-400 px-4 py-2 text-sm font-medium text-emerald-300 transition hover:bg-emerald-400 hover:text-slate-950 disabled:opacity-50"
            :disabled="uploading"
            @click="triggerFilePicker"
          >
            <ArrowUpTrayIcon class="mr-2 h-5 w-5" />
            アップロード
          </button>
          <button
            type="button"
            class="inline-flex items-center rounded-full border border-cyan-400 px-4 py-2 text-sm font-medium text-cyan-300 transition hover:bg-cyan-400 hover:text-slate-950 disabled:border-slate-700 disabled:text-slate-600"
            :disabled="!selectedDocuments.length || zipJobLoading"
            @click="startZipJob()"
          >
            <ArrowDownTrayIcon class="mr-2 h-5 w-5" />
            ダウンロード
          </button>
          <button
            type="button"
            class="inline-flex items-center rounded-full border border-rose-400 px-4 py-2 text-sm font-medium text-rose-300 transition hover:bg-rose-400 hover:text-slate-950 disabled:border-slate-700 disabled:text-slate-600"
            :disabled="!selectedDocuments.length || zipJobLoading"
            @click="removeSelected"
          >
            <TrashIcon class="mr-2 h-5 w-5" />
            削除
          </button>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-4xl px-6 py-10">
      <!-- Upload Progress -->
      <section v-if="uploadProgress.size > 0" class="mb-6">
        <h3 class="mb-3 text-sm font-semibold text-slate-300">アップロード進捗</h3>
        <div class="space-y-3">
          <div
            v-for="[fileId, progress] in Array.from(uploadProgress.entries())"
            :key="fileId"
            class="rounded-lg border border-slate-800 bg-slate-900 p-4"
          >
            <div class="mb-2 flex items-center justify-between">
              <span class="text-sm font-medium text-slate-200">{{ progress.filename }}</span>
              <span class="text-xs text-slate-400">
                {{ progress.currentChunk }}/{{ progress.totalChunks }} チャンク
              </span>
            </div>
            <div class="mb-2 h-2 w-full overflow-hidden rounded-full bg-slate-800">
              <div
                class="h-full transition-all duration-300"
                :class="{
                  'bg-emerald-500': progress.status === 'completed',
                  'bg-cyan-500': progress.status === 'uploading',
                  'bg-rose-500': progress.status === 'error',
                }"
                :style="{ width: progress.progress + '%' }"
              ></div>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span
                :class="{
                  'text-emerald-400': progress.status === 'completed',
                  'text-cyan-400': progress.status === 'uploading',
                  'text-amber-400': progress.status === 'finalizing',
                  'text-rose-400': progress.status === 'error',
                }"
              >
                {{ progress.statusText || (progress.status === 'completed' ? '完了' : progress.status === 'error' ? 'エラー' : progress.status === 'finalizing' ? 'ファイルを結合中...' : 'アップロード中') }}
              </span>
              <span class="text-slate-400">{{ progress.progress }}%</span>
            </div>
            <div v-if="progress.error" class="mt-2 text-xs text-rose-400">
              {{ progress.error }}
            </div>
          </div>
        </div>
      </section>

      <section v-if="flashMessage" class="mb-6 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
        {{ flashMessage }}
      </section>

      <section v-if="errorMessage" class="mb-6 rounded-lg border border-rose-400/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
        {{ errorMessage }}
      </section>

      <section v-if="zipJobStatus" class="mb-6 rounded-lg border border-slate-800 bg-slate-900 px-4 py-4 text-sm">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p class="font-medium text-slate-100">ZIPジョブ: {{ zipJobStatus.job_id }}</p>
            <p class="text-slate-400">
              ステータス:
              <span class="font-semibold text-emerald-300">{{ zipJobStatus.status }}</span>
              <span v-if="zipJobStatus.progress !== null">・進捗: {{ zipJobStatus.progress }}%</span>
            </p>
            <p v-if="zipJobStatus.error" class="text-rose-300">エラー: {{ zipJobStatus.error }}</p>
          </div>
          <div v-if="zipJobStatus.download_url">
            <a
              :href="zipJobStatus.download_url"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center rounded-full border border-cyan-400 px-4 py-2 text-sm font-semibold text-cyan-300 transition hover:bg-cyan-400 hover:text-slate-950"
            >
              ZIPをダウンロード
            </a>
            <p v-if="zipJobStatus.expires_in_minutes" class="mt-1 text-xs text-slate-500">
              有効期限: 約 {{ zipJobStatus.expires_in_minutes }} 分
            </p>
          </div>
        </div>
      </section>

      <section class="overflow-hidden rounded-xl border border-slate-800 bg-slate-900">
        <header class="flex items-center justify-between border-b border-slate-800 px-6 py-4">
          <div class="flex items-center gap-3">
            <input
              id="selectAll"
              type="checkbox"
              class="h-4 w-4 accent-emerald-400"
              :checked="allSelected"
              :indeterminate="isIndeterminate"
              @change="toggleAll"
            />
            <label for="selectAll" class="text-sm font-medium text-slate-300">全て選択</label>
          </div>
          <p class="text-xs text-slate-400">
            選択中: <span class="font-semibold text-emerald-300">{{ selectedDocuments.length }}</span> 件
          </p>
        </header>

        <table class="min-w-full divide-y divide-slate-800">
          <thead class="bg-slate-950 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
            <tr>
              <th class="px-6 py-3">ファイル名</th>
              <th class="px-6 py-3">サイズ</th>
              <th class="px-6 py-3">登録日時</th>
              <th class="px-6 py-3 text-right">操作</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-800 text-sm">
            <tr
              v-for="document in documents"
              :key="document.id"
              class="hover:bg-slate-950/60"
            >
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <input
                    type="checkbox"
                    class="h-4 w-4 accent-emerald-400"
                    :value="document.id"
                    v-model="selected"
                  />
                  <component
                    :is="fileIcon(determineType(document.mime_type, document.extension))"
                    class="h-6 w-6"
                    :class="iconClass(determineType(document.mime_type, document.extension))"
                  />
                  <div>
                    <p class="font-medium text-slate-100">{{ document.original_name }}</p>
                    <p class="text-xs text-slate-500">{{ renderFileType(document) }}</p>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 text-slate-300">{{ formatSize(document.size) }}</td>
              <td class="px-6 py-4 text-slate-300">{{ formatTimestamp(document.created_at) }}</td>
              <td class="px-6 py-4 text-right">
                <button
                  type="button"
                  class="rounded-full border border-cyan-400 px-3 py-1 text-xs font-semibold text-cyan-300 transition hover:bg-cyan-400 hover:text-slate-950 disabled:border-slate-700 disabled:text-slate-600"
                  :disabled="zipJobLoading"
                  @click="startZipJob([document.id])"
                >
                  個別DL
                </button>
              </td>
            </tr>
            <tr v-if="loadingDocuments">
              <td class="px-6 py-10 text-center text-slate-400" colspan="4">
                ファイル一覧を読み込んでいます…
              </td>
            </tr>
            <tr v-else-if="!documents.length">
              <td class="px-6 py-10 text-center text-slate-500" colspan="4">
                表示できるファイルがありません。アップロードボタンから追加してください。
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </main>

    <input
      ref="fileInput"
      type="file"
      class="hidden"
      multiple
      @change="handleFilesChosen"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, TrashIcon } from '@heroicons/vue/20/solid';
import { DocumentIcon, PhotoIcon, MusicalNoteIcon, FilmIcon } from '@heroicons/vue/24/outline';
import {
  createZipJob,
  getZipJobStatus,
  listDocuments,
  type DocumentRecord,
  type ZipJobStatusResponse,
} from '@/api/files';
import { useChunkedUpload } from '@/composables/useChunkedUpload';

type FileType = 'pdf' | 'image' | 'audio' | 'video' | 'other';

const documents = ref<DocumentRecord[]>([]);
const selected = ref<number[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const loadingDocuments = ref(false);
const errorMessage = ref<string | null>(null);
const flashMessage = ref<string | null>(null);
const zipJobStatus = ref<ZipJobStatusResponse | null>(null);
const pollTimer = ref<number | null>(null);

// Use chunked upload composable
const { uploading, uploadProgress, uploadFiles, clearProgress } = useChunkedUpload();

const selectedDocuments = computed(() =>
  documents.value.filter((document) => selected.value.includes(document.id)),
);

const allSelected = computed(
  () => Boolean(documents.value.length) && selected.value.length === documents.value.length,
);

const isIndeterminate = computed(
  () => selected.value.length > 0 && selected.value.length < documents.value.length,
);

const zipJobLoading = computed(() => {
  if (!zipJobStatus.value) return false;
  return zipJobStatus.value.status === 'queued' || zipJobStatus.value.status === 'processing';
});

const triggerFilePicker = () => {
  fileInput.value?.click();
};

const determineType = (mime: string | null, extension: string | null): FileType => {
  const normalizedMime = mime?.toLowerCase() ?? '';
  const normalizedExt = extension?.toLowerCase() ?? '';

  if (normalizedMime.includes('pdf') || normalizedExt === 'pdf') return 'pdf';
  if (normalizedMime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(normalizedExt))
    return 'image';
  if (normalizedMime.startsWith('audio/') || ['mp3', 'wav', 'aac'].includes(normalizedExt)) return 'audio';
  if (normalizedMime.startsWith('video/') || ['mp4', 'mov', 'avi'].includes(normalizedExt)) return 'video';
  return 'other';
};

const fileIcon = (type: FileType) => {
  switch (type) {
    case 'pdf':
      return DocumentIcon;
    case 'image':
      return PhotoIcon;
    case 'audio':
      return MusicalNoteIcon;
    case 'video':
      return FilmIcon;
    default:
      return DocumentIcon;
  }
};

const iconClass = (type: FileType) => {
  switch (type) {
    case 'pdf':
      return 'text-rose-300';
    case 'image':
      return 'text-emerald-300';
    case 'audio':
      return 'text-sky-300';
    case 'video':
      return 'text-amber-300';
    default:
      return 'text-slate-300';
  }
};

const renderFileType = (document: DocumentRecord) => {
  const type = determineType(document.mime_type, document.extension);
  const map: Record<FileType, string> = {
    pdf: 'PDF',
    image: '画像',
    audio: '音声',
    video: '動画',
    other: 'その他',
  };
  return map[type];
};

const formatSize = (bytes?: number | null): string => {
  if (!bytes) return '-';
  if (bytes >= 1_000_000_000) return `${(bytes / 1_000_000_000).toFixed(1)} GB`;
  if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
  if (bytes >= 1_000) return `${(bytes / 1_000).toFixed(1)} KB`;
  return `${bytes} B`;
};

const formatTimestamp = (timestamp: string): string => {
  try {
    return new Intl.DateTimeFormat('ja-JP', {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(timestamp));
  } catch {
    return timestamp;
  }
};

const fetchDocuments = async () => {
  loadingDocuments.value = true;
  errorMessage.value = null;
  try {
    const { data } = await listDocuments();
    documents.value = data.data;
  } catch (error) {
    console.error(error);
    errorMessage.value = 'ファイル一覧の取得に失敗しました。';
  } finally {
    loadingDocuments.value = false;
  }
};

const handleFilesChosen = async (event: Event) => {
  const target = event.target as HTMLInputElement | null;
  const pickedFiles = target?.files;
  if (!pickedFiles || pickedFiles.length === 0) return;

  errorMessage.value = null;
  flashMessage.value = null;
  clearProgress();

  try {
    console.log('[QueueWork] Starting chunked upload for', pickedFiles.length, 'file(s)');
    
    const filesArray = Array.from(pickedFiles);
    const uploadedDocuments = await uploadFiles(filesArray, 'uploads');

    // Add uploaded documents to the list
    documents.value = [...uploadedDocuments, ...documents.value];
    
    // Select newly uploaded documents
    selected.value = uploadedDocuments.map((doc) => doc.id);
    
    flashMessage.value = `${uploadedDocuments.length} ファイルのアップロードが完了しました。`;
    
    console.log('[QueueWork] Upload completed', {
      count: uploadedDocuments.length,
      ids: uploadedDocuments.map(d => d.id),
    });

  } catch (error: any) {
    console.error('[QueueWork] Upload failed:', error);
    errorMessage.value = error.message || 'アップロードに失敗しました。';
  } finally {
    if (target) target.value = '';
    
    setTimeout(() => {
      flashMessage.value = null;
      clearProgress();
    }, 5000);
  }
};

const toggleAll = (event: Event) => {
  const checked = (event.target as HTMLInputElement).checked;
  selected.value = checked ? documents.value.map((document) => document.id) : [];
};

const removeSelected = () => {
  if (!selected.value.length) return;
  const ids = new Set(selected.value);
  documents.value = documents.value.filter((document) => !ids.has(document.id));
  selected.value = [];
};

const clearPollTimer = () => {
  if (pollTimer.value) {
    window.clearInterval(pollTimer.value);
    pollTimer.value = null;
  }
};

const pollZipJobStatus = (jobId: string) => {
  clearPollTimer();
  pollTimer.value = window.setInterval(async () => {
    try {
      const { data } = await getZipJobStatus(jobId);
      zipJobStatus.value = data;

      if (data.status === 'completed' || data.status === 'failed') {
        clearPollTimer();
        if (data.status === 'completed' && data.download_url) {
          flashMessage.value = 'ZIPファイルの準備が整いました。';
        } else if (data.status === 'failed') {
          errorMessage.value = data.error ?? 'ZIPファイルの生成に失敗しました。';
        }
      }
    } catch (error) {
      console.error(error);
      errorMessage.value = 'ZIPジョブの状態取得に失敗しました。';
      clearPollTimer();
    }
  }, 2500);
};

const startZipJob = async (documentIds?: number[]) => {
  const targets = documentIds ?? selected.value;
  if (!targets.length) return;

  errorMessage.value = null;
  flashMessage.value = null;
  zipJobStatus.value = null;

  try {
    const { data } = await createZipJob(targets);
    zipJobStatus.value = {
      job_id: data.job_id,
      status: data.status as ZipJobStatusResponse['status'],
      progress: data.progress,
    };
    pollZipJobStatus(data.job_id);
  } catch (error) {
    console.error(error);
    errorMessage.value = 'ZIPジョブの作成に失敗しました。';
  }
};

onMounted(() => {
  fetchDocuments();
});

onUnmounted(() => {
  clearPollTimer();
});
</script>
