<script lang="ts" setup>
interface Props {
  companyName?: string;
  companySiteLink?: string;
  date?: string;
  icp?: string;
  icpLink?: string;
}

defineOptions({
  name: 'Copyright',
});

withDefaults(defineProps<Props>(), {
  companyName: 'Vben Admin',
  companySiteLink: '',
  date: '2024',
  icp: '',
  icpLink: '',
});
</script>

<template>
  <div class="text-md flex-center">
    <!-- ICP 备案链接 -->
    <a
      v-if="icp"
      :href="icpLink || 'https://beian.miit.gov.cn/'"
      class="mx-1 hover:text-primary-hover"
      rel="noopener noreferrer"
      target="_blank"
    >
      {{ icp }}
    </a>

    <!-- 版权文本 -->
    Copyright © {{ date }}

    <!-- 公司链接 -->
    <a
      v-if="companyName"
      :href="companySiteLink || 'javascript:void(0)'"
      class="mx-1 hover:text-primary-hover"
      target="_blank"
    >
      {{ companyName }}
    </a>
  </div>
</template>
