<script lang="ts" setup>
import { Page, useVbenDrawer } from '@vben/common-ui';
import { Plus } from '@vben/icons';
import { ElButton, ElMessage, ElMessageBox } from 'element-plus';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { listing, actions, act, displayCell, type Row } from '#/api/business';
import { can } from '#/api/session';
import Form from './modules/form.vue';
const [FormDrawer, formDrawerApi] = useVbenDrawer({
  connectedComponent: Form,
  destroyOnClose: true,
});
const [Grid, gridApi] = useVbenVxeGrid({
  formOptions: {
    schema: [{ component: 'Input', fieldName: 'q', label: '搜索' }],
    submitOnChange: true,
  },
  gridOptions: {
    columns: [
      { field: 'number', title: '订单编号', minWidth: 120 },
      { field: 'user_id', title: '用户 ID', minWidth: 120 },
      { field: 'total', title: '金额（分）', minWidth: 120 },
      { field: 'currency', title: '币种', minWidth: 120 },
      { field: 'status', title: '状态', minWidth: 120 },
      { field: 'created', title: '创建时间', minWidth: 120 },
      {
        title: '操作',
        minWidth: 200,
        fixed: 'right' as const,
        slots: { default: 'action' },
      },
    ].map((column) => ({ ...column, formatter: displayCell })),
    height: 'auto',
    keepSource: true,
    pagerConfig: { pageSize: 25, pageSizes: [25] },
    rowConfig: { keyField: 'id' },
    proxyConfig: {
      ajax: {
        query: async (
          { page }: { page: { currentPage: number } },
          values: Record<string, any>,
        ) => listing('orders', page.currentPage, String(values.q || '')),
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
function onRefresh() {
  gridApi.query();
}
function onCreate() {
  formDrawerApi.setData(null).open();
}
async function onAction(code: string, row: Row) {
  if (code === 'edit') {
    formDrawerApi.setData(row).open();
    return;
  }
  try {
    await ElMessageBox.confirm('确认执行此操作？', '操作确认', {
      type: 'warning',
    });
  } catch {
    return;
  }
  await act('orders', code, row);
  ElMessage.success('操作成功');
  onRefresh();
}
</script>
<template>
  <Page auto-content-height>
    <FormDrawer @success="onRefresh" />
    <Grid table-title="订单管理">
      <template #toolbar-tools>
        <ElButton v-if="can('orders.manage')" type="primary" @click="onCreate"
          ><Plus class="size-5" />新增</ElButton
        >
      </template>
      <template #action="{ row }">
        <ElButton
          v-for="action in actions('orders', row)"
          :key="action.code"
          link
          type="primary"
          @click="onAction(action.code, row)"
          >{{ action.label }}</ElButton
        >
      </template>
    </Grid>
  </Page>
</template>
