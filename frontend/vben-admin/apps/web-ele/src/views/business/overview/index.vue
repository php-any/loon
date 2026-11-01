<script lang="ts" setup>
import type { AnalysisOverviewItem } from '@vben/common-ui';
import { shallowRef, onMounted } from 'vue';
import { AnalysisOverview, Page } from '@vben/common-ui';
import {
  SvgCardIcon,
  SvgCakeIcon,
  SvgDownloadIcon,
  SvgBellIcon,
} from '@vben/icons';
import { requestClient } from '#/api/request';
const items = shallowRef<AnalysisOverviewItem[]>([]);
onMounted(async () => {
  const data = await requestClient.get('/admin/overview');
  items.value = [
    {
      icon: SvgCardIcon,
      title: '我的项目',
      totalTitle: '当前项目总数',
      value: data.projects,
      totalValue: data.projects,
    },
    {
      icon: SvgCakeIcon,
      title: '我的任务',
      totalTitle: '当前任务总数',
      value: data.tasks,
      totalValue: data.tasks,
    },
    {
      icon: SvgDownloadIcon,
      title: '已完成',
      totalTitle: '已完成任务总数',
      value: data.completed,
      totalValue: data.completed,
    },
    {
      icon: SvgBellIcon,
      title: '待完成',
      totalTitle: '待完成任务总数',
      value: data.tasks - data.completed,
      totalValue: data.tasks - data.completed,
    },
  ];
});
</script>
<template>
  <Page><AnalysisOverview :items="items" /></Page>
</template>
