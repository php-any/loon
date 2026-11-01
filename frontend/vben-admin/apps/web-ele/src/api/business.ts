import type { VbenFormSchema } from '#/adapter/form';
import { requestClient } from './request';
import { can } from './session';
export type Row = Record<string, any>;
const management = new Set(['users', 'roles', 'menus', 'settings', 'audit']);
export function endpoint(resource: string) {
  return `/admin/${management.has(resource) ? 'manage' : 'operations'}/${resource}`;
}
export const mutate = (resource: string, data: Row) =>
  requestClient.post(endpoint(resource), data);
export async function listing(
  resource: string,
  page = 1,
  q = '',
): Promise<Row> {
  if (resource === 'permissions') {
    const data = await requestClient.get<Row>(endpoint('roles'));
    const items = Object.entries(data.permissions)
      .filter(([code, label]) => `${code} ${label}`.includes(q))
      .map(([code, label]) => ({
        id: code,
        code,
        label,
        roles: data.items
          .filter((r: Row) =>
            JSON.parse(r.permissions).some(
              (p: string) => p === '*' || p === code,
            ),
          )
          .map((r: Row) => r.name)
          .join('、'),
      }));
    return {
      items: items.slice((page - 1) * 25, page * 25),
      total: items.length,
    };
  }
  const data = await requestClient.get<Row>(endpoint(resource), {
    params: resource === 'inbox' ? { page, status: q || 'all' } : { page, q },
  });
  if (resource === 'users')
    data.items = data.items.map((r: Row) => ({
      ...r,
      roleNames: data.assignments
        .filter((a: Row) => Number(a.user_id) === Number(r.id))
        .map(
          (a: Row) =>
            data.roles.find(
              (role: Row) => Number(role.id) === Number(a.role_id),
            )?.name,
        )
        .join('、'),
      roles: data.assignments
        .filter((a: Row) => Number(a.user_id) === Number(r.id))
        .map((a: Row) => String(a.role_id)),
    }));
  if (['roles', 'menus', 'channels'].includes(resource)) {
    const items = data.items.filter((r: Row) =>
      Object.values(r).join(' ').includes(q),
    );
    return {
      ...data,
      items: items.slice((page - 1) * 25, page * 25),
      total: items.length,
    };
  }
  return data;
}
export const textField = (
  fieldName: string,
  label: string,
  required = true,
): Extract<VbenFormSchema, { component: 'Input' }> => ({
  component: 'Input',
  fieldName,
  label,
  ...(required ? { rules: 'required' } : {}),
});
export const numberField = (
  fieldName: string,
  label: string,
  min = 0,
): VbenFormSchema => ({
  component: 'InputNumber',
  fieldName,
  label,
  rules: 'required',
  componentProps: { min, precision: 0 },
});
export const selectField = (
  fieldName: string,
  label: string,
  options: { label: string; value: any }[],
  multiple = false,
): VbenFormSchema => ({
  component: 'Select',
  fieldName,
  label,
  componentProps: { options, multiple, filterable: true },
});
export async function formData(resource: string, row: Row | null) {
  let schema: VbenFormSchema[] = [];
  let values: Row = { ...row };
  if (resource === 'users') {
    const data = await listing('users');
    schema = [
      textField('name', '姓名'),
      ...(!row
        ? [
            textField('email', '邮箱'),
            {
              ...textField('password', '密码（10–72 位）'),
              componentProps: { type: 'password', showPassword: true },
            },
          ]
        : []),
      selectField(
        'roles',
        '角色',
        data.roles.map((r: Row) => ({ label: r.name, value: String(r.id) })),
        true,
      ),
      { component: 'Switch', fieldName: 'enabled', label: '启用' },
    ];
    values = {
      ...values,
      roles: row?.roles || ['2'],
      enabled: row ? !!Number(row.enabled) : true,
    };
  } else if (resource === 'roles') {
    const data = await requestClient.get<Row>(endpoint('roles'));
    schema = [
      textField('name', '角色名称'),
      selectField(
        'permissions',
        '权限',
        Object.entries(data.permissions)
          .filter(([code]) => can(code))
          .map(([value, label]) => ({ label: String(label), value })),
        true,
      ),
    ];
    values.permissions = row ? JSON.parse(row.permissions) : [];
  } else if (resource === 'menus') {
    const data = await requestClient.get<Row>(endpoint('menus'));
    schema = [
      textField('label', '菜单名称'),
      textField('path', '地址（分组留空）', false),
      selectField('parent_id', '父级菜单', [
        { label: '顶级菜单', value: 0 },
        ...data.items
          .filter((r: Row) => r.id !== row?.id)
          .map((r: Row) => ({ label: r.label, value: Number(r.id) })),
      ]),
      selectField('permission', '权限', [
        { label: '无（仅菜单分组）', value: '' },
        ...Object.entries(data.permissions).map(([value, label]) => ({
          value,
          label: String(label),
        })),
      ]),
      numberField('sort_order', '排序', -10000),
      { component: 'Switch', fieldName: 'enabled', label: '启用' },
    ];
    values = {
      path: '',
      permission: '',
      sort_order: 0,
      ...values,
      parent_id: Number(row?.parent_id || 0),
      enabled: row ? !!Number(row.enabled) : true,
    };
  } else if (resource === 'orders') {
    schema = [
      numberField('user_id', '用户 ID', 1),
      textField('name', '商品名称'),
      numberField('quantity', '数量', 1),
      numberField('unit_price', '单价（分）'),
    ];
    values = { quantity: 1, unit_price: 0, request_key: crypto.randomUUID() };
  } else if (resource === 'notifications') {
    schema = [
      numberField('user_id', '用户 ID', 1),
      textField('title', '标题'),
      {
        ...textField('body', '内容'),
        componentProps: { type: 'textarea', rows: 5 },
      },
    ];
    values = { request_key: crypto.randomUUID() };
  } else if (resource === 'channels') {
    schema = [
      { component: 'Switch', fieldName: 'enabled', label: '启用' },
      ...(row?.channel === 'webhook'
        ? [
            textField('url', 'Webhook HTTPS 地址', false),
            textField('secret_env', '密钥环境变量名称', false),
          ]
        : []),
    ];
    values.enabled = !!Number(row?.enabled);
  }
  return { schema, values };
}
export async function save(resource: string, row: Row | null, values: Row) {
  if (resource === 'users')
    return mutate(resource, {
      action: 'save',
      id: row?.id || 0,
      name: values.name,
      enabled: !!values.enabled,
      roles: (values.roles || []).join(','),
      ...(!row ? { email: values.email, password: values.password } : {}),
    });
  if (resource === 'roles')
    return mutate(resource, {
      action: 'save',
      id: row?.id || 0,
      name: values.name,
      permissions: (values.permissions || []).join(','),
    });
  if (resource === 'menus')
    return mutate(resource, {
      action: 'save',
      id: row?.id || 0,
      label: values.label,
      path: values.path || '',
      permission: values.permission || '',
      parent_id: values.parent_id || 0,
      sort_order: values.sort_order,
      enabled: !!values.enabled,
    });
  if (resource === 'orders')
    return mutate(resource, {
      action: 'create',
      user_id: values.user_id,
      request_key: values.request_key,
      items: [
        {
          name: values.name,
          quantity: values.quantity,
          unit_price: values.unit_price,
        },
      ],
    });
  if (resource === 'notifications')
    return mutate(resource, {
      action: 'send',
      user_id: values.user_id,
      title: values.title,
      body: values.body,
      request_key: values.request_key,
    });
  if (resource === 'channels')
    return mutate(resource, {
      action: 'save',
      channel: row?.channel,
      enabled: !!values.enabled,
      url: values.url || '',
      secret_env: values.secret_env || '',
    });
}
export function actions(resource: string, row: Row) {
  const result: { code: string; label: string }[] = [];
  if (
    ['users', 'menus', 'channels'].includes(resource) &&
    can(`${resource}.manage`)
  )
    result.push({ code: 'edit', label: '编辑' });
  if (resource === 'roles' && Number(row.id) > 2 && can('roles.manage'))
    result.push(
      { code: 'edit', label: '编辑' },
      { code: 'delete', label: '删除' },
    );
  if (resource === 'menus' && can('menus.manage'))
    result.push({ code: 'delete', label: '删除' });
  if (resource === 'orders' && can('orders.manage'))
    for (const status of (
      {
        pending: ['processing', 'cancelled'],
        processing: ['completed', 'cancelled'],
      } as Record<string, string[]>
    )[row.status] || [])
      result.push({
        code: status,
        label: (
          {
            processing: '开始处理',
            completed: '完成',
            cancelled: '取消订单',
          } as Row
        )[status],
      });
  if (
    ['queue', 'notifications'].includes(resource) &&
    row.state === 'failed' &&
    can(`${resource}.retry`)
  )
    result.push({ code: 'retry', label: '重试' });
  if (resource === 'inbox' && !row.read_at)
    result.push({ code: 'read', label: '标记已读' });
  return result;
}
export async function act(resource: string, code: string, row: Row) {
  return mutate(resource, {
    action: resource === 'orders' ? 'transition' : code,
    ...(resource === 'channels'
      ? { channel: row.channel }
      : { id: Number(row.id) }),
    ...(resource === 'orders' ? { expected: row.status, status: code } : {}),
    ...(code === 'toggle'
      ? { expected: !!Number(row.enabled), enabled: !Number(row.enabled) }
      : {}),
  });
}

export function displayCell({
  cellValue,
  column,
}: {
  cellValue: any;
  column: { field: string };
}) {
  if (['created', 'read_at', 'next_run', 'verified_at'].includes(column.field))
    return cellValue
      ? new Date(Number(cellValue) * 1000).toLocaleString()
      : '—';
  if (column.field === 'enabled') return Number(cellValue) ? '启用' : '停用';
  if (column.field === 'status' || column.field === 'state')
    return (
      (
        {
          pending: '待处理',
          processing: '处理中',
          completed: '已完成',
          cancelled: '已取消',
          running: '运行中',
          failed: '失败',
          sending: '发送中',
          sent: '已发送',
          done: '已完成',
        } as Row
      )[cellValue] || cellValue
    );
  return Array.isArray(cellValue) ? cellValue.join('、') : (cellValue ?? '—');
}
