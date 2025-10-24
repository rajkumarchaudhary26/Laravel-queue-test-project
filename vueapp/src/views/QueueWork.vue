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
          <!-- Test Mode Button -->
          <button
            type="button"
            class="inline-flex items-center rounded-full border border-amber-400 px-4 py-2 text-sm font-medium text-amber-300 transition hover:bg-amber-400 hover:text-slate-950"
            @click="showTestPanel = !showTestPanel"
          >
            テストモード
          </button>
        </div>
      </div>
    </header>

    <!-- Test Panel -->
    <div v-if="showTestPanel" class="border-b border-amber-500/30 bg-amber-950/20">
      <div class="mx-auto max-w-4xl px-6 py-6">
        <h2 class="mb-4 text-lg font-semibold text-amber-300">並行ダウンロードテスト</h2>
        
        <!-- File Selection for Test -->
        <div class="mb-4 rounded-lg border border-amber-500/50 bg-amber-900/10 p-4">
          <div class="mb-2 text-sm font-medium text-amber-200">テスト用ファイル選択</div>
          <div class="space-y-2 max-h-60 overflow-y-auto">
            <label
              v-for="doc in documents"
              :key="doc.id"
              class="flex items-center gap-3 rounded border border-amber-600/30 bg-slate-900/50 p-2 hover:bg-slate-800/50 cursor-pointer"
            >
              <input
                type="checkbox"
                :value="doc.id"
                v-model="testFileSelection"
                class="h-4 w-4 accent-amber-400"
              />
              <component
                :is="fileIcon(determineType(doc.mime_type, doc.extension))"
                class="h-5 w-5"
                :class="iconClass(determineType(doc.mime_type, doc.extension))"
              />
              <div class="flex-1 min-w-0">
                <div class="text-sm text-slate-200 truncate">{{ doc.original_name }}</div>
                <div class="text-xs text-slate-500">{{ formatSize(doc.size) }}</div>
              </div>
            </label>
          </div>
          <div class="mt-2 text-xs text-amber-400">
            選択中: {{ testFileSelection.length }} ファイル (各ジョブにこのファイルセットが使われます)
          </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <button
            @click="runConcurrentTest(2)"
            :disabled="testFileSelection.length === 0 || testRunning"
            class="rounded-lg border border-amber-400 bg-amber-500/10 px-4 py-3 text-left transition hover:bg-amber-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <div class="text-sm font-semibold text-amber-300">2並行ジョブ</div>
            <div class="mt-1 text-xs text-amber-400">2つのZIPジョブを同時実行</div>
          </button>
          <button
            @click="runConcurrentTest(3)"
            :disabled="testFileSelection.length === 0 || testRunning"
            class="rounded-lg border border-amber-400 bg-amber-500/10 px-4 py-3 text-left transition hover:bg-amber-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <div class="text-sm font-semibold text-amber-300">3並行ジョブ</div>
            <div class="mt-1 text-xs text-amber-400">3つのZIPジョブを同時実行</div>
          </button>
          <button
            @click="runConcurrentTest(5)"
            :disabled="testFileSelection.length === 0 || testRunning"
            class="rounded-lg border border-amber-400 bg-amber-500/10 px-4 py-3 text-left transition hover:bg-amber-500/20 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <div class="text-sm font-semibold text-amber-300">5並行ジョブ</div>
            <div class="mt-1 text-xs text-amber-400">5つのZIPジョブを同時実行 (キュー競合)</div>
          </button>
        </div>
        <div v-if="testRunning" class="mt-4 rounded-lg border border-amber-500/50 bg-amber-900/20 p-4">
          <div class="text-sm font-medium text-amber-200">テスト実行中...</div>
          <div class="mt-2 text-xs text-amber-300">{{ testStatus }}</div>
        </div>
        <div v-if="testResults" class="mt-4 rounded-lg border border-emerald-500/50 bg-emerald-900/20 p-4">
          <div class="text-sm font-semibold text-emerald-200">テスト完了</div>
          <div class="mt-2 space-y-1 text-xs text-emerald-300">
            <div>並行数: {{ testResults.concurrentCount }}</div>
            <div>テストファイル: {{ testResults.fileNames.join(', ') }}</div>
            <div>総ファイルサイズ: {{ formatSize(testResults.totalFileSize) }}</div>
            <div>開始時刻: {{ testResults.startTime }}</div>
            <div>終了時刻: {{ testResults.endTime }}</div>
            <div>総所要時間: {{ testResults.totalDuration }}秒</div>
            <div>平均ジョブ時間: {{ testResults.averageJobTime }}秒</div>
            <div>個別ジョブ時間: {{ testResults.jobTimes.join('s, ') }}s</div>
            <div>ジョブID: {{ testResults.jobIds.join(', ') }}</div>
          </div>
          <button
            @click="copyTestResults"
            class="mt-3 rounded border border-emerald-400 px-3 py-1 text-xs text-emerald-300 hover:bg-emerald-400 hover:text-slate-950"
          >
            結果をコピー
          </button>
        </div>
        <div class="mt-4 text-xs text-amber-400">
          テストを実行するには、上記からファイルを選択してください。
        </div>
      </div>
    </div>

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

interface TestResult {
  concurrentCount: number;
  startTime: string;
  endTime: string;
  totalDuration: number;
  averageJobTime: number;
  jobIds: string[];
  jobTimes: number[];
  fileNames: string[];
  totalFileSize: number;
}

const documents = ref<DocumentRecord[]>([]);
const selected = ref<number[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const loadingDocuments = ref(false);
const errorMessage = ref<string | null>(null);
const flashMessage = ref<string | null>(null);
const zipJobStatus = ref<ZipJobStatusResponse | null>(null);
const pollTimer = ref<number | null>(null);

// Test mode
const showTestPanel = ref(false);
const testRunning = ref(false);
const testStatus = ref('');
const testResults = ref<TestResult | null>(null);
const testFileSelection = ref<number[]>([]);

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

// Concurrent test function
const runConcurrentTest = async (concurrentCount: number) => {
  if (testFileSelection.value.length === 0) {
    errorMessage.value = 'テスト用のファイルを選択してください';
    return;
  }

  testRunning.value = true;
  testResults.value = null;
  errorMessage.value = null;
  flashMessage.value = null;

  try {
    // Get selected documents info for reporting
    const selectedDocs = documents.value.filter(d => testFileSelection.value.includes(d.id));
    const fileNames = selectedDocs.map(d => d.original_name);
    const totalFileSize = selectedDocs.reduce((sum, d) => sum + (d.size || 0), 0);
    
    testStatus.value = `${concurrentCount}個のZIPジョブを同時送信中...`;
    testStatus.value += `\n使用ファイル: ${fileNames.join(', ')}`;
    
    const startTime = new Date();
    const startTimeStr = startTime.toLocaleTimeString('ja-JP', { 
      hour: '2-digit', 
      minute: '2-digit', 
      second: '2-digit',
      fractionalSecondDigits: 3 
    });

    console.log(`[TEST] Starting ${concurrentCount} concurrent jobs at ${startTimeStr}`);
    console.log(`[TEST] Document IDs:`, testFileSelection.value);
    console.log(`[TEST] File names:`, fileNames);
    console.log(`[TEST] Total file size:`, totalFileSize, 'bytes');

    // Create all jobs simultaneously using Promise.all
    const jobPromises = Array.from({ length: concurrentCount }, async () => {
      const { data } = await createZipJob(testFileSelection.value);
      console.log(`[TEST] Job created: ${data.job_id}`);
      return data.job_id;
    });

    const jobIds = await Promise.all(jobPromises);
    
    testStatus.value = `${jobIds.length}個のジョブが作成されました。完了を待機中...`;
    console.log(`[TEST] All jobs submitted:`, jobIds);

    // Poll all jobs until completion
    const jobCompletionTimes = new Map<string, number>();
    const pollInterval = 2000; // 2 seconds
    const maxWaitTime = 1800000; // 30 minutes

    const waitForCompletion = async () => {
      const startPollTime = Date.now();
      
      while (jobCompletionTimes.size < jobIds.length) {
        if (Date.now() - startPollTime > maxWaitTime) {
          throw new Error('Timeout waiting for jobs to complete');
        }

        await Promise.all(
          jobIds.map(async (jobId) => {
            if (jobCompletionTimes.has(jobId)) return;

            try {
              const { data } = await getZipJobStatus(jobId);
              
              if (data.status === 'completed') {
                const completionTime = Date.now();
                jobCompletionTimes.set(jobId, completionTime);
                console.log(`[TEST] Job ${jobId} completed at ${new Date(completionTime).toLocaleTimeString('ja-JP')}`);
              } else if (data.status === 'failed') {
                throw new Error(`Job ${jobId} failed: ${data.error}`);
              }
            } catch (error) {
              console.error(`[TEST] Error checking job ${jobId}:`, error);
            }
          })
        );

        testStatus.value = `完了: ${jobCompletionTimes.size}/${jobIds.length}`;
        
        if (jobCompletionTimes.size < jobIds.length) {
          await new Promise(resolve => setTimeout(resolve, pollInterval));
        }
      }
    };

    await waitForCompletion();

    const endTime = new Date();
    const endTimeStr = endTime.toLocaleTimeString('ja-JP', { 
      hour: '2-digit', 
      minute: '2-digit', 
      second: '2-digit',
      fractionalSecondDigits: 3 
    });

    const totalDuration = (endTime.getTime() - startTime.getTime()) / 1000;
    
    // Calculate individual job times
    const jobTimes = jobIds.map(jobId => {
      const completionTime = jobCompletionTimes.get(jobId)!;
      return (completionTime - startTime.getTime()) / 1000;
    });
    
    const averageJobTime = jobTimes.reduce((a, b) => a + b, 0) / jobTimes.length;

    testResults.value = {
      concurrentCount,
      startTime: startTimeStr,
      endTime: endTimeStr,
      totalDuration: Math.round(totalDuration * 100) / 100,
      averageJobTime: Math.round(averageJobTime * 100) / 100,
      jobIds,
      jobTimes: jobTimes.map(t => Math.round(t * 100) / 100),
      fileNames,
      totalFileSize,
    };

    console.log('[TEST] Test completed:', testResults.value);
    
    flashMessage.value = `テスト完了: ${concurrentCount}並行ジョブ - ${totalDuration.toFixed(2)}秒`;

  } catch (error: any) {
    console.error('[TEST] Test failed:', error);
    errorMessage.value = `テスト失敗: ${error.message}`;
  } finally {
    testRunning.value = false;
    testStatus.value = '';
  }
};

const copyTestResults = () => {
  if (!testResults.value) return;

  const text = `
並行ダウンロードテスト結果
================================
並行数: ${testResults.value.concurrentCount}
テストファイル: ${testResults.value.fileNames.join(', ')}
総ファイルサイズ: ${formatSize(testResults.value.totalFileSize)}
開始時刻: ${testResults.value.startTime}
終了時刻: ${testResults.value.endTime}
総所要時間: ${testResults.value.totalDuration}秒
平均ジョブ時間: ${testResults.value.averageJobTime}秒
個別ジョブ時間: ${testResults.value.jobTimes.join('s, ')}s
ジョブID: ${testResults.value.jobIds.join(', ')}
  `.trim();

  navigator.clipboard.writeText(text);
  flashMessage.value = 'テスト結果をクリップボードにコピーしました';
  setTimeout(() => flashMessage.value = null, 3000);
};

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

    documents.value = [...uploadedDocuments, ...documents.value];
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
