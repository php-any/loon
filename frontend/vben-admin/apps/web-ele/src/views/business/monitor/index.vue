<script lang="ts" setup>
import { Page } from '@vben/common-ui';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { listing } from '#/api/business';
const [Grid] = useVbenVxeGrid({
  gridOptions: {
    columns: [
      { field: 'name', title: '服务' },
      { field: 'active', title: '运行中' },
      {
        field: 'seen',
        title: '最近心跳',
        formatter: ({ cellValue }: { cellValue: any }) =>
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
  <Page auto-content-height><Grid table-title="运行监控" /></Page>
</template>
