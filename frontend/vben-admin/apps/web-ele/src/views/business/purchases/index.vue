<script setup lang="ts">
import { Page } from '@vben/common-ui';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { requestClient } from '#/api/request';
const [Grid] = useVbenVxeGrid({
  formOptions: { schema: [{ component: 'Input', fieldName: 'q', label: '订单号 / 用户邮箱' }], submitOnChange: true },
  gridOptions: {
    columns: [
      { field: 'number', title: '订单号', minWidth: 240 }, { field: 'customer_id', title: '用户 ID', width: 100 },
      { field: 'name', title: '用户姓名', minWidth: 120 }, { field: 'email', title: '用户邮箱', minWidth: 220 },
      { field: 'total', title: '模拟金额', width: 130, formatter: ({ cellValue }: { cellValue: number }) => `¥${(Number(cellValue) / 100).toFixed(2)}` },
      { field: 'status', title: '状态', width: 130, formatter: () => '模拟支付成功' },
      { field: 'created', title: '支付时间', minWidth: 180, formatter: ({ cellValue }: { cellValue: number }) => new Date(Number(cellValue) * 1000).toLocaleString() },
    ], height: 'auto', pagerConfig: { pageSize: 25, pageSizes: [25] },
    proxyConfig: { ajax: { query: ({ page }: { page: { currentPage: number } }, values: Record<string, any>) => requestClient.get('/admin/purchases', { params: { page: page.currentPage, q: values.q || '' } }) } },
    toolbarConfig: { refresh: true, search: true },
  },
});
</script>
<template><Page auto-content-height description="官网用户的模拟支付订单，不产生实际扣款。"><Grid table-title="用户订单" /></Page></template>
