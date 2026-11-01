<script lang="ts" setup>
import type { VbenFormSchema } from '@vben/common-ui';
import { AuthenticationLogin, z } from '@vben/common-ui';
import { useAuthStore } from '#/store';
defineOptions({ name: 'Login' });
const authStore = useAuthStore();
const formSchema: VbenFormSchema[] = [
  {
    component: 'VbenInput',
    fieldName: 'username',
    label: '邮箱',
    componentProps: { placeholder: '请输入账号邮箱', autocomplete: 'username' },
    rules: z.string().email('请输入有效邮箱'),
  },
  {
    component: 'VbenInputPassword',
    fieldName: 'password',
    label: '密码',
    componentProps: {
      placeholder: '请输入密码',
      autocomplete: 'current-password',
    },
    rules: z.string().min(10, '密码至少 10 位').max(72),
  },
];
</script>
<template>
  <AuthenticationLogin
    :form-schema="formSchema"
    :loading="authStore.loginLoading"
    :show-register="false" title="管理员登录" description="仅限后台管理账号，官网用户请在首页登录。" :show-code-login="false"
    :show-qrcode-login="false"
    :show-third-party-login="false"
    @submit="authStore.authLogin"
  />
</template>

