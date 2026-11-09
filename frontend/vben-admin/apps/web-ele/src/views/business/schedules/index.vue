<script lang="ts" setup>
import { Page } from '@vben/common-ui';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { listing, displayCell } from '#/api/business';

const [Grid] = useVbenVxeGrid({
  formOptions: {
    schema: [{ component: 'Input', fieldName: 'q', label: '搜索' }],
    submitOnChange: true,
  },
  gridOptions: {
    columns: [
      { field: 'class', title: '任务类', minWidth: 300 },
      { field: 'schedule', title: '调度', minWidth: 200 },
      { field: 'state', title: '运行状态', minWidth: 280 },
    ].map((column) => ({ ...column, formatter: displayCell })),
    height: 'auto',
    keepSource: true,
    pagerConfig: { pageSize: 25, pageSizes: [25] },
    rowConfig: { keyField: 'class' },
    proxyConfig: {
      ajax: {
        query: async (
          { page }: { page: { currentPage: number } },
          values: Record<string, any>,
        ) => listing('schedules', page.currentPage, String(values.q || '')),
      },
    },
    toolbarConfig: {
      custom: true,
      export: false,
      refresh: true,
      search: true,
      zoom: true,
    },
  },
});
</script>
<template>
  <Page auto-content-height>
    <Grid table-title="定时器（由代码声明）" />
  </Page>
</template>
