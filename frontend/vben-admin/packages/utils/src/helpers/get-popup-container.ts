/**
 * 如果节点位于表单内，则返回表单元素；
 * 否则返回指定元素的父节点，
 * 未提供元素时返回 document.body。
 */
export function getPopupContainer(node?: HTMLElement): HTMLElement {
  return (
    node?.closest('form') ?? (node?.parentNode as HTMLElement) ?? document.body
  );
}
