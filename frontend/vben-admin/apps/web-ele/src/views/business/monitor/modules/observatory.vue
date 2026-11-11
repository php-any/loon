<script lang="ts" setup>
import { computed, reactive, ref, watch } from 'vue';
import {
  ElButton,
  ElCard,
  ElCol,
  ElDescriptions,
  ElDrawer,
  ElInput,
  ElMessage,
  ElPagination,
  ElRow,
  ElTable,
  ElTableColumn,
  ElTabPane,
  ElTabs,
} from 'element-plus';
import {
  ENTRY_TYPES,
  entryTypeLabel,
  fetchMonitoringCorrelation,
  fetchMonitoringEntries,
  fetchMonitoringOverview,
  fetchMonitoringSession,
  type MonitoringEntryType,
  type MonitoringFilters,
} from '#/api/monitoring';

const filters = reactive<MonitoringFilters>({
  tag: '',
  correlation_id: '',
  path: '',
  status: '',
});

const counts = ref<Record<string, number>>({});
const sessions = ref<Record<string, unknown>[]>([]);
const sessionPage = ref(1);
const sessionTotal = ref(0);
const sessionPerPage = 20;

const entryType = ref<MonitoringEntryType>('http');
const entries = ref<Record<string, unknown>[]>([]);
const entryPage = ref(1);
const entryTotal = ref(0);
const entryPerPage = 25;

const loading = ref(false);
const drawerOpen = ref(false);
const drawerTitle = ref('');
const drawerPayload = ref('');

const correlationInput = ref('');
const correlationSessions = ref<Record<string, unknown>[]>([]);

const activeFilterParams = computed(() => {
  const out: MonitoringFilters = {};
  for (const key of ['tag', 'correlation_id', 'path', 'status'] as const) {
    const value = filters[key]?.trim();
    if (value) {
      out[key] = value;
    }
  }
  return out;
});

async function loadOverview() {
  loading.value = true;
  try {
    const data = await fetchMonitoringOverview(
      sessionPage.value,
      sessionPerPage,
      activeFilterParams.value,
    );
    counts.value = data.counts;
    sessions.value = data.sessions;
    sessionTotal.value = data.total;
  } catch (error: unknown) {
    ElMessage.error(error instanceof Error ? error.message : '加载概览失败');
  } finally {
    loading.value = false;
  }
}

async function loadEntries() {
  loading.value = true;
  try {
    const data = await fetchMonitoringEntries(
      entryType.value,
      entryPage.value,
      entryPerPage,
      activeFilterParams.value,
    );
    entries.value = data.items;
    entryTotal.value = data.total;
  } catch (error: unknown) {
    ElMessage.error(error instanceof Error ? error.message : '加载条目失败');
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  sessionPage.value = 1;
  entryPage.value = 1;
  void loadOverview();
  void loadEntries();
}

async function openSession(id: number) {
  try {
    const data = await fetchMonitoringSession(id);
    drawerTitle.value = `会话 #${id}`;
    drawerPayload.value = JSON.stringify(data, null, 2);
    drawerOpen.value = true;
  } catch (error: unknown) {
    ElMessage.error(error instanceof Error ? error.message : '加载会话失败');
  }
}

async function loadCorrelation() {
  const id = correlationInput.value.trim();
  if (!id) {
    ElMessage.warning('请输入 correlation_id');
    return;
  }
  try {
    const data = await fetchMonitoringCorrelation(id);
    correlationSessions.value = data.sessions;
  } catch (error: unknown) {
    ElMessage.error(error instanceof Error ? error.message : '加载关联链失败');
  }
}

watch(entryType, () => {
  entryPage.value = 1;
  void loadEntries();
});

watch(sessionPage, () => {
  void loadOverview();
});

watch(entryPage, () => {
  void loadEntries();
});

void loadOverview();
void loadEntries();
</script>

<template>
  <div class="flex flex-col gap-4">
    <ElCard shadow="never">
      <template #header>筛选</template>
      <ElRow :gutter="12">
        <ElCol :span="6"><ElInput v-model="filters.tag" placeholder="tag" /></ElCol>
        <ElCol :span="6"
          ><ElInput v-model="filters.correlation_id" placeholder="correlation_id"
        /></ElCol>
        <ElCol :span="6"><ElInput v-model="filters.path" placeholder="path" /></ElCol>
        <ElCol :span="4"><ElInput v-model="filters.status" placeholder="status" /></ElCol>
        <ElCol :span="2"
          ><ElButton type="primary" :loading="loading" @click="applyFilters"
            >查询</ElButton
          ></ElCol
        >
      </ElRow>
    </ElCard>

    <ElCard shadow="never">
      <template #header>各类型条目数量</template>
      <ElRow :gutter="8">
        <ElCol v-for="type in ENTRY_TYPES" :key="type" :span="6" class="mb-2">
          <ElDescriptions :column="1" border size="small">
            <ElDescriptionsItem :label="entryTypeLabel(type)">
              {{ counts[type] ?? 0 }}
            </ElDescriptionsItem>
          </ElDescriptions>
        </ElCol>
      </ElRow>
    </ElCard>

    <ElCard shadow="never">
      <template #header>最近会话</template>
      <ElTable :data="sessions" v-loading="loading" size="small">
        <ElTableColumn label="ID" width="90">
          <template #default="{ row }">
            <ElButton link type="primary" @click="openSession(Number(row.id))">
              {{ row.id }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="channel" label="通道" width="100" />
        <ElTableColumn prop="correlation_id" label="关联 ID" min-width="180" />
        <ElTableColumn prop="duration_ms" label="耗时 ms" width="100" />
        <ElTableColumn label="标签" min-width="200">
          <template #default="{ row }">
            <code class="text-xs">{{ JSON.stringify(row.tags) }}</code>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-model:current-page="sessionPage"
        class="mt-3 justify-end"
        layout="prev, pager, next, total"
        :page-size="sessionPerPage"
        :total="sessionTotal"
      />
    </ElCard>

  <ElCard shadow="never">
      <template #header>按类型条目</template>
      <ElTabs v-model="entryType">
        <ElTabPane
          v-for="type in ENTRY_TYPES"
          :key="type"
          :label="entryTypeLabel(type)"
          :name="type"
        />
      </ElTabs>
      <ElTable :data="entries" v-loading="loading" size="small">
        <ElTableColumn label="会话" width="90">
          <template #default="{ row }">
            <ElButton
              link
              type="primary"
              @click="openSession(Number(row.session_id))"
            >
              #{{ row.session_id }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="event" label="事件" min-width="160" />
        <ElTableColumn prop="correlation_id" label="关联" min-width="160" />
        <ElTableColumn label="载荷" min-width="240">
          <template #default="{ row }">
            <code class="text-xs">{{ JSON.stringify(row.payload) }}</code>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-model:current-page="entryPage"
        class="mt-3 justify-end"
        layout="prev, pager, next, total"
        :page-size="entryPerPage"
        :total="entryTotal"
      />
    </ElCard>

    <ElCard shadow="never">
      <template #header>按 correlation_id 串联</template>
      <div class="mb-3 flex gap-2">
        <ElInput v-model="correlationInput" placeholder="correlation_id" />
        <ElButton type="primary" @click="loadCorrelation">加载</ElButton>
      </div>
      <ElTable :data="correlationSessions" size="small">
        <ElTableColumn label="会话" width="90">
          <template #default="{ row }">
            <ElButton link type="primary" @click="openSession(Number(row.id))">
              {{ row.id }}
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="channel" label="通道" width="100" />
        <ElTableColumn prop="duration_ms" label="耗时 ms" width="100" />
        <ElTableColumn label="标签" min-width="200">
          <template #default="{ row }">
            <code class="text-xs">{{ JSON.stringify(row.tags) }}</code>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElDrawer v-model="drawerOpen" :title="drawerTitle" size="50%">
      <pre class="whitespace-pre-wrap break-all text-xs">{{ drawerPayload }}</pre>
    </ElDrawer>
  </div>
</template>
