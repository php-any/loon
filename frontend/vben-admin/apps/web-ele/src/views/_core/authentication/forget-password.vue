<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { AuthenticationForgetPassword, z } from '@vben/common-ui';
import { ElMessage } from 'element-plus';
import { requestClient } from '#/api/request';
const loading = ref(false);
const sent = ref(false);
const router = useRouter();
const formSchema = computed((): VbenFormSchema[] =>
  sent.value
    ? [
        {
          component: 'VbenInput',
          fieldName: 'token',
          label: '邮件中的验证码',
          rules: z.string().length(64),
        },
        {
          component: 'VbenInputPassword',
          fieldName: 'password',
          label: '新密码',
          rules: z.string().min(10).max(72),
        },
      ]
    : [
        {
          component: 'VbenInput',
          fieldName: 'email',
          label: '邮箱',
          rules: z.string().email(),
        },
      ],
);
async function handleSubmit(values: Record<string, any>) {
  loading.value = true;
  try {
    const data = await requestClient.post(
      sent.value ? '/account/reset' : '/account/request-reset',
      sent.value
        ? { token: values.token, password: values.password }
        : { email: values.email },
    );
    ElMessage.success(data.message);
    if (sent.value) {
      await router.push('/auth/login');
    } else {
      sent.value = true;
    }
  } finally {
    loading.value = false;
  }
}
</script>
<template>
  <AuthenticationForgetPassword
    :form-schema="formSchema"
    :loading="loading"
    :submit-button-text="sent ? '重置密码' : '发送验证码'"
    @submit="handleSubmit"
  />
</template>
