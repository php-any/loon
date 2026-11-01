export interface PageProps {
  title?: string;
  description?: string;
  contentClass?: string;
  /**
   * 根据content可见高度自适应
   */
  autoContentHeight?: boolean;
  headerClass?: string;
  footerClass?: string;
  /**
   * 自定义高度偏移量，单位为像素，用于在启用
   * autoContentHeight 时调整内容区域尺寸
   * @default 0
   */
  heightOffset?: number;
  /**
   * 是否将页脚固定在页面布局底部。
   * 页脚保持在文档流中，因此不会遮挡页面内容。
   * @default false
   */
  footerFixed?: boolean;
}
