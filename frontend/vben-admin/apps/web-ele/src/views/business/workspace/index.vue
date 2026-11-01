<script lang="ts" setup>
import { Page, useVbenDrawer } from '@vben/common-ui';
import { Plus } from '@vben/icons';
import { ElButton, ElMessageBox } from 'element-plus';
import { useVbenVxeGrid } from '#/adapter/vxe-table';
import { requestClient } from '#/api/request';
import Form from './modules/form.vue';
const [FormDrawer, drawer] = useVbenDrawer({
  connectedComponent: Form,
  destroyOnClose: true,
});
const [Grid, grid] = useVbenVxeGrid({
  formOptions: {
    schema: [{ component: 'Input', fieldName: 'q', label: '搜索' }],
    submitOnChange: true,
  },
  gridOptions: {
    columns: [
      { field: 'id', title: 'ID', width: 80 },
      { field: 'title', title: '任务', minWidth: 200 },
      { field: 'project', title: '项目', minWidth: 160 },
      {
        field: 'completed',
        title: '状态',
        formatter: ({ cellValue }: { cellValue: any }) =>
          Number(cellValue) ? '已完成' : '待完成',
      },
      { title: '操作', minWidth: 180, slots: { default: 'action' } },
    ],
    height: 'auto',
    pagerConfig: { pageSize: 25, pageSizes: [25] },
    proxyConfig: {
      ajax: {
        query: async (
          { page }: { page: { currentPage: number } },
          values: Record<string, any>,
        ) => {
          const data = await requestClient.get('/studio/state');
          const items = data.tasks
            .filter((t: any) => t.title.includes(values.q || ''))
            .map((t: any) => ({
              ...t,
              project:
                data.projects.find((p: any) => p.id === t.project_id)?.name ||
                '未分组',
            }));
          return {
            items: items.slice(
              (page.currentPage - 1) * 25,
              page.currentPage * 25,
            ),
            total: items.length,
          };
        },
      },
    },
    toolbarConfig: { refresh: true, search: true, custom: true, zoom: true },
  },
});
async function toggle(row: any) {
  await requestClient.request(`/studio/tasks/${row.id}`, {
    method: 'PATCH',
    data: { completed: !Number(row.completed) },
  });
  grid.query();
}
async function remove(row: any) {
  try {
    await ElMessageBox.confirm('确认删除此任务？', '删除任务', {
      type: 'warning',
    });
  } catch {
    return;
  }
  await requestClient.delete(`/studio/tasks/${row.id}`);
  grid.query();
}
</script>
<template>
  <Page auto-content-height
    ><FormDrawer @success="grid.query()" /><Grid table-title="项目与任务"
      ><template #toolbar-tools
        ><ElButton @click="drawer.setData({ kind: 'project' }).open()"
          >新建项目</ElButton
        ><ElButton
          type="primary"
          @click="drawer.setData({ kind: 'task' }).open()"
          ><Plus class="size-5" />新增任务</ElButton
        ></template
      ><template #action="{ row }"
        ><ElButton link type="primary" @click="toggle(row)">{{
          Number(row.completed) ? '恢复待办' : '完成'
        }}</ElButton
        ><ElButton link type="danger" @click="remove(row)"
          >删除</ElButton
        ></template
      ></Grid
    ></Page
  >
</template>
