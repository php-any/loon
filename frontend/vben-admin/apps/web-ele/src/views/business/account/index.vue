<script lang="ts" setup>
import { Page } from '@vben/common-ui';
import {
  ElCard,
  ElDescriptions,
  ElDescriptionsItem,
  ElButton,
  ElMessage,
} from 'element-plus';
import { useVbenForm } from '#/adapter/form';
import { session } from '#/api/session';
import { requestClient } from '#/api/request';
import { getUserInfoApi } from '#/api';
async function requestVerify() {
  const data = await requestClient.post('/account/request-verify', {});
  ElMessage.success(data.message);
}
const [Form] = useVbenForm({
  schema: [
    {
      component: 'Input',
      fieldName: 'token',
      label: '邮箱验证码',
      rules: 'required',
    },
  ],
  submitButtonOptions: { content: '验证邮箱' },
  async handleSubmit(values) {
    const data = await requestClient.post('/account/verify', {
      token: values.token,
    });
    ElMessage.success(data.message);
    await getUserInfoApi();
  },
});
</script>
<template>
  <Page title="个人中心"
    ><ElCard
      ><ElDescriptions :column="1" border
        ><ElDescriptionsItem label="姓名">{{
          session?.user.name
        }}</ElDescriptionsItem
        ><ElDescriptionsItem label="邮箱">{{
          session?.user.email
        }}</ElDescriptionsItem
        ><ElDescriptionsItem label="邮箱状态">{{
          session?.verified ? '已验证' : '未验证'
        }}</ElDescriptionsItem></ElDescriptions
      ><ElButton v-if="!session?.verified" type="primary" @click="requestVerify"
        >发送验证邮件</ElButton
      ><Form v-if="!session?.verified" /></ElCard
  ></Page>
</template>
