<script setup lang="ts">
import { ref } from 'vue';

import { useQuery } from '@tanstack/vue-query';
import { Button } from 'antdv-next';

const count = ref(-1);
async function fetchApi() {
  count.value += 1;
  return new Promise((_resolve, reject) => {
    setTimeout(() => {
      reject(new Error('something went wrong!'));
    }, 1000);
  });
}

const { error, isFetching, refetch } = useQuery({
  enabled: false, // 禁止查询挂载时自动重新获取
  queryFn: fetchApi,
  queryKey: ['queryKey'],
  retry: 3, // 失败请求重试三次后再显示错误
});

const onClick = async () => {
  count.value = -1;
  await refetch();
};
</script>

<template>
  <Button :loading="isFetching" @click="onClick"> 发起错误重试 </Button>
  <p v-if="count > 0" class="my-3">重试次数{{ count }}</p>
  <p>{{ error }}</p>
</template>
