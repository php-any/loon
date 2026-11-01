<script lang="ts" setup>
import { ref } from 'vue';
import { useVbenDrawer } from '@vben/common-ui';
import { ElMessage } from 'element-plus';
import { useVbenForm } from '#/adapter/form';
import { formData, save, type Row } from '#/api/business';
const emit = defineEmits(['success']);
const row = ref<Row | null>(null);
const [Form, formApi] = useVbenForm({ schema: [], showDefaultActions: false });
const [Drawer, drawerApi] = useVbenDrawer({
  async onOpenChange(open) {
    if (!open) return;
    drawerApi.lock();
    try {
      row.value = drawerApi.getData() || null;
      await formApi.resetForm();
      const data = await formData('menus', row.value);
      formApi.setState({ schema: data.schema });
      await formApi.setValues(data.values);
    } finally {
      drawerApi.unlock();
    }
  },
  async onConfirm() {
    const { valid } = await formApi.validate();
    if (!valid) return;
    drawerApi.lock();
    try {
      await save('menus', row.value, await formApi.getValues());
      emit('success');
      drawerApi.close();
      ElMessage.success('保存成功');
    } finally {
      drawerApi.unlock();
    }
  },
});
</script>
<template>
  <Drawer :title="row ? '编辑菜单管理' : '新增菜单管理'"><Form /></Drawer>
</template>
