<script lang="ts" setup>
import { ref } from 'vue';
import { Page } from '@vben/common-ui';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { listing } from '#/api/business';
import { ElTabPane, ElTabs } from 'element-plus';
import Observatory from './modules/observatory.vue';

const activeTab = ref('services');

const [Grid] = useVbenVxeGrid({
  gridOptions: {
    columns: [
      { field: 'name', title: '服务' },
      { field: 'active', title: '运行中' },
      {
        field: 'seen',
        title: '最近心跳',
        formatter: ({ cellValue }: { cellValue: unknown }) =>
          cellValue
            ? new Date(Number(cellValue) * 1000).toLocaleString()
            : '暂无心跳',
      },
      { field: 'ticks', title: '执行次数' },
    ],
    height: 'auto',
    pagerConfig: { enabled: false },
    proxyConfig: {
      ajax: {
        query: async () => {
          const data = await listing('monitor');
          return { items: data.services };
        },
      },
    },
    toolbarConfig: { refresh: true, custom: true, zoom: true },
  },
});
</script>
<template>
  <Page auto-content-height>
    <ElTabs v-model="activeTab" class="h-full">
      <ElTabPane label="服务心跳" name="services">
        <Grid table-title="运行监控" />
      </ElTabPane>
      <ElTabPane label="观测台" name="observatory" lazy>
        <Observatory />
      </ElTabPane>
    </ElTabs>
  </Page>
</template>
