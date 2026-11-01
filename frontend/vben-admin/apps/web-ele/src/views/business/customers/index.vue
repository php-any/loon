<script setup lang="ts">
import { ref } from 'vue';
import { Page, useVbenDrawer } from '@vben/common-ui';
import { ElButton, ElMessage } from 'element-plus';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { useVbenForm } from '#/adapter/form';
import { requestClient } from '#/api/request';
import { textField, type Row } from '#/api/business';
const selected = ref<Row | null>(null);
const [Form, formApi] = useVbenForm({ schema: [], showDefaultActions: false });
const [Drawer, drawerApi] = useVbenDrawer({
  async onConfirm() {
    if (!(await formApi.validate()).valid) return;
    drawerApi.lock();
    try {
      const values = await formApi.getValues();
      await requestClient.post('/admin/customers', { id: selected.value?.id || 0, name: values.name, enabled: !!values.enabled, ...(!selected.value ? { email: values.email, password: values.password } : {}) });
      drawerApi.close();
      gridApi.query();
      ElMessage.success('用户已保存');
    } finally { drawerApi.unlock(); }
  },
});
async function edit(row: Row | null) {
  selected.value = row;
  drawerApi.open();
  await formApi.resetForm();
  formApi.setState({ schema: [textField('name', '用户姓名'), ...(!row ? [textField('email', '邮箱'), { ...textField('password', '密码（10–72 字节）'), componentProps: { type: 'password', showPassword: true } }] : []), { component: 'Switch', fieldName: 'enabled', label: '启用账号' }] });
  await formApi.setValues({ name: row?.name || '', enabled: row ? !!Number(row.enabled) : true });
}
const [Grid, gridApi] = useVbenVxeGrid({
  formOptions: { schema: [{ component: 'Input', fieldName: 'q', label: '姓名 / 邮箱' }], submitOnChange: true },
  gridOptions: {
    columns: [
      { field: 'id', title: '用户 ID', width: 100 }, { field: 'name', title: '姓名', minWidth: 140 },
      { field: 'email', title: '邮箱', minWidth: 220 },
      { field: 'enabled', title: '状态', width: 100, formatter: ({ cellValue }: { cellValue: number }) => Number(cellValue) ? '启用' : '停用' },
      { field: 'created', title: '注册时间', minWidth: 180, formatter: ({ cellValue }: { cellValue: number }) => new Date(Number(cellValue) * 1000).toLocaleString() },
      { title: '操作', width: 100, slots: { default: 'action' } },
    ], height: 'auto', pagerConfig: { pageSize: 25, pageSizes: [25] },
    proxyConfig: { ajax: { query: ({ page }: { page: { currentPage: number } }, values: Row) => requestClient.get('/admin/customers', { params: { page: page.currentPage, q: values.q || '' } }) } },
    toolbarConfig: { refresh: true, search: true },
  },
});
</script>
<template>
  <Page auto-content-height description="管理官网注册用户；用户账号独立于管理员，不分配后台角色。">
    <Drawer :title="selected ? '编辑用户' : '新增用户'"><Form /></Drawer>
    <Grid table-title="用户管理">
      <template #toolbar-tools><ElButton type="primary" @click="edit(null)">新增用户</ElButton></template>
      <template #action="{ row }"><ElButton link type="primary" @click="edit(row)">编辑</ElButton></template>
    </Grid>
  </Page>
</template>
