<script lang="ts" setup>
import { onMounted } from 'vue';
import { Page } from '@vben/common-ui';
import { ElCard, ElMessage } from 'element-plus';
import { useVbenForm } from '#/adapter/form';
import { listing, mutate, textField } from '#/api/business';
const [Form, formApi] = useVbenForm({
  schema: [
    textField('site_name', '网站名称'),
    {
      ...textField('site_description', '网站描述', false),
      componentProps: { type: 'textarea', rows: 3 },
    },
    {
      component: 'Switch',
      fieldName: 'registration_enabled',
      label: '开放官网用户注册',
    },
  ],
  submitButtonOptions: { content: '保存配置' },
  async handleSubmit(values) {
    await mutate('settings', {
      action: 'save',
      site_name: values.site_name,
      site_description: values.site_description || '',
      registration_enabled: !!values.registration_enabled,
    });
    ElMessage.success('配置已保存');
  },
});
onMounted(async () => {
  const { settings } = await listing('settings');
  await formApi.setValues({
    ...settings,
    registration_enabled: settings.registration_enabled === 'true',
  });
});
</script>
<template>
  <Page title="系统配置"
    ><ElCard><Form /></ElCard
  ></Page>
</template>

