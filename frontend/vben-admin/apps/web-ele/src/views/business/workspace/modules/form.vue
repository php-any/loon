<script lang="ts" setup>
import { ref } from 'vue';
import { useVbenDrawer } from '@vben/common-ui';
import { useVbenForm } from '#/adapter/form';
import { textField, selectField } from '#/api/business';
import { requestClient } from '#/api/request';
const emit = defineEmits(['success']);
const kind = ref('task');
const [Form, form] = useVbenForm({ schema: [], showDefaultActions: false });
const [Drawer, drawer] = useVbenDrawer({
  async onOpenChange(open) {
    if (!open) return;
    drawer.lock();
    try {
      kind.value = (drawer.getData() as { kind: string }).kind;
      const data = await requestClient.get('/studio/state');
      form.setState({
        schema:
          kind.value === 'project'
            ? [textField('name', '项目名称')]
            : [
                textField('title', '任务内容'),
                selectField(
                  'project_id',
                  '项目',
                  data.projects.map((p: any) => ({
                    value: p.id,
                    label: p.name,
                  })),
                ),
              ],
      });
      await form.resetForm();
    } finally {
      drawer.unlock();
    }
  },
  async onConfirm() {
    if (!(await form.validate()).valid) return;
    drawer.lock();
    try {
      const values = await form.getValues();
      await requestClient.post(
        kind.value === 'project' ? '/studio/projects' : '/studio/tasks',
        kind.value === 'project'
          ? { name: values.name }
          : {
              title: values.title,
              ...(values.project_id ? { project_id: values.project_id } : {}),
            },
      );
      emit('success');
      drawer.close();
    } finally {
      drawer.unlock();
    }
  },
});
</script>
<template>
  <Drawer :title="kind === 'project' ? '新建项目' : '新增任务'"
    ><Form
  /></Drawer>
</template>
