<script lang="ts" setup>
import { Maximize, Minimize } from '@vben-core/icons';

import { useFullscreen } from '@vueuse/core';

import { VbenIconButton } from '../button';

defineOptions({ name: 'FullScreen' });

withDefaults(
  defineProps<{
    tooltip?: string;
  }>(),
  {
    tooltip: '',
  },
);

const { isFullscreen, toggle } = useFullscreen();

// 重新检查全屏状态
isFullscreen.value = !!(
  document.fullscreenElement ||
  // @ts-expect-error - 标准 DOM 类型定义不包含厂商专用的全屏 API
  document.webkitFullscreenElement ||
  // @ts-expect-error - 标准 DOM 类型定义不包含厂商专用的全屏 API
  document.mozFullScreenElement ||
  // @ts-expect-error - 标准 DOM 类型定义不包含厂商专用的全屏 API
  document.msFullscreenElement
);
</script>
<template>
  <VbenIconButton
    :tooltip="tooltip || undefined"
    class="hover:animate-[shrink_0.3s_ease-in-out]"
    @click="toggle"
  >
    <Minimize v-if="isFullscreen" class="text-foreground size-4" />
    <Maximize v-else class="text-foreground size-4" />
  </VbenIconButton>
</template>
