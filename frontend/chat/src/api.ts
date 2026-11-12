export type SessionUser = { id: number; name: string; email: string; csrf: string };

let csrf = '';

export function setCsrf(token: string) {
  csrf = token;
}

async function request<T>(
  path: string,
  init: RequestInit & { json?: unknown } = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  if (init.json !== undefined) {
    headers.set('Content-Type', 'application/json');
    init.body = JSON.stringify(init.json);
  }
  const method = (init.method ?? 'GET').toUpperCase();
  if (!['GET', 'HEAD'].includes(method) && csrf) {
    headers.set('X-CSRF-Token', csrf);
  }
  const response = await fetch(path, {
    ...init,
    headers,
    credentials: 'include',
  });
  if (!response.ok) {
    const text = await response.text();
    throw new Error(text || `HTTP ${response.status}`);
  }
  if (response.status === 204) {
    return undefined as T;
  }
  return (await response.json()) as T;
}

export const api = {
  session: () => request<{ user: SessionUser }>('/api/im/session'),
  login: (email: string, password: string) =>
    request<{ csrf: string; user: { id: number } }>('/api/im/login', {
      method: 'POST',
      json: { email, password },
    }),
  logout: () => request<{ ok: boolean }>('/api/im/logout', { method: 'POST', json: {} }),
  contacts: (q: string) => request<{ items: { id: number; name: string; email: string }[] }>(`/api/im/contacts?q=${encodeURIComponent(q)}`),
  conversations: () => request<{ items: ConversationSummary[] }>('/api/im/conversations'),
  conversation: (id: number) => request<ConversationDetail>(`/api/im/conversations/${id}`),
  messages: (id: number, page: number) =>
    request<{ items: ChatMessage[]; total: number }>(`/api/im/conversations/${id}/messages?page=${page}`),
  createConversation: (payload: { type: 'direct' | 'group'; title?: string; member_ids: number[] }) =>
    request<ConversationSummary>('/api/im/conversations', { method: 'POST', json: payload }),
  rename: (id: number, title: string) =>
    request<ConversationDetail>(`/api/im/conversations/${id}`, { method: 'PATCH', json: { title } }),
  addMembers: (id: number, member_ids: number[]) =>
    request<ConversationDetail>(`/api/im/conversations/${id}/members`, { method: 'POST', json: { member_ids } }),
  removeMember: (id: number, userId: number) =>
    request<unknown>(`/api/im/conversations/${id}/members/${userId}`, { method: 'DELETE' }),
  dissolve: (id: number) => request<{ dissolved: boolean }>(`/api/im/conversations/${id}`, { method: 'DELETE' }),
  sendMessage: (id: number, body: string) =>
    request<ChatMessage>(`/api/im/conversations/${id}/messages`, { method: 'POST', json: { body } }),
  markRead: (id: number, message_id: number) =>
    request<{ ok: boolean }>(`/api/im/conversations/${id}/read`, { method: 'POST', json: { message_id } }),
};

export type ChatMessage = {
  id: number;
  conversation_id: number;
  user_id: number;
  body: string;
  created_at: number;
  sender_name: string;
};

export type ConversationSummary = {
  id: number;
  type: 'direct' | 'group';
  title: string | null;
  updated_at: number;
  last_read_message_id: number | null;
  peer: { id: number; name: string } | null;
  members_count: number | null;
  last_message: {
    id: number;
    user_id: number;
    sender_name: string;
    body: string;
    created_at: number;
  } | null;
  unread_count: number;
};

export type ConversationDetail = ConversationSummary & {
  members: { id: number; name: string; role: 'owner' | 'member' }[];
};
