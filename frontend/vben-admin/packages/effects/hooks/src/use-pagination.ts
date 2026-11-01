import type { Ref } from 'vue';

import { computed, ref, unref, watch } from 'vue';

/**
 * 对项目数组进行分页
 * @param list 待分页的数组
 * @param pageNo 当前页码，从 1 开始
 * @param pageSize 每页项目数
 * @returns 分页后的数组切片
 * @throws {Error} 当 pageNo 或 pageSize 无效时抛出
 */
function pagination<T = any>(list: T[], pageNo: number, pageSize: number): T[] {
  if (pageNo < 1) throw new Error('Page number must be positive');
  if (pageSize < 1) throw new Error('Page size must be positive');

  const offset = (pageNo - 1) * Number(pageSize);
  const ret =
    offset + pageSize >= list.length
      ? list.slice(offset)
      : list.slice(offset, offset + pageSize);
  return ret;
}

export function usePagination<T = any>(
  list: Ref<T[]>,
  pageSize: number,
  totalChangeToFirstPage = true,
) {
  const currentPage = ref(1);
  const pageSizeRef = ref(pageSize);

  const totalPages = computed(() =>
    Math.ceil(unref(list).length / unref(pageSizeRef)),
  );

  const paginationList = computed(() => {
    return pagination(unref(list), unref(currentPage), unref(pageSizeRef));
  });

  const total = computed(() => {
    return unref(list).length;
  });

  if (totalChangeToFirstPage) {
    watch(total, () => {
      setCurrentPage(1);
    });
  }

  function setCurrentPage(page: number) {
    if (page === 1 && unref(totalPages) === 0) {
      currentPage.value = 1;
    } else {
      if (page < 1 || page > unref(totalPages)) {
        throw new Error('Invalid page number');
      }
      currentPage.value = page;
    }
  }

  function setPageSize(pageSize: number) {
    if (pageSize < 1) {
      throw new Error('Page size must be positive');
    }
    pageSizeRef.value = pageSize;
    // 重置到第一页，防止出现无效状态
    currentPage.value = 1;
  }

  return { setCurrentPage, total, setPageSize, paginationList, currentPage };
}
