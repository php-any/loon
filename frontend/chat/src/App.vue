<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  api,
  setCsrf,
  type ChatMessage,
  type ConversationDetail,
  type ConversationSummary,
  type SessionUser,
} from './api';

const user = ref<SessionUser | null>(null);
const loading = ref(true);
const loginForm = reactive({ email: '', password: '' });
const conversations = ref<ConversationSummary[]>([]);
const search = ref('');
const activeId = ref<number | null>(null);
const activeDetail = ref<ConversationDetail | null>(null);
const messages = ref<ChatMessage[]>([]);
const messagePage = ref(1);
const messageTotal = ref(0);
const draft = ref('');
const messageBox = ref<HTMLElement | null>(null);
const joinedConversationId = ref<number | null>(null);

const createVisible = ref(false);
const createType = ref<'direct' | 'group'>('direct');
const createTitle = ref('');
const contactQuery = ref('');
const contactResults = ref<{ id: number; name: string; email: string }[]>([]);
const pickedMembers = ref<number[]>([]);
const directPeer = ref<number | null>(null);
const addMemberVisible = ref(false);
const addMemberIds = ref<number[]>([]);

let socket: WebSocket | null = null;
let reconnectAttempt = 0;
let reconnectTimer: number | undefined;

const filteredConversations = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return conversations.value;
  return conversations.value.filter((item) => displayTitle(item).toLowerCase().includes(q));
});

function displayTitle(item: ConversationSummary) {
  if (item.type === 'direct') return item.peer?.name ?? '私聊';
  return item.title ?? '群聊';
}

function formatTime(ts: number) {
  return new Date(ts * 1000).toLocaleString();
}

function upsertConversation(summary: ConversationSummary) {
  const index = conversations.value.findIndex((c) => c.id === summary.id);
  if (index >= 0) conversations.value[index] = summary;
  else conversations.value.unshift(summary);
  conversations.value.sort((a, b) => b.updated_at - a.updated_at);
}

async function bootstrap() {
  try {
    const session = await api.session();
    user.value = session.user;
    setCsrf(session.user.csrf);
    await refreshConversations();
    connectSocket();
  } catch {
    user.value = null;
  } finally {
    loading.value = false;
  }
}

async function doLogin() {
  const result = await api.login(loginForm.email, loginForm.password);
  setCsrf(result.csrf);
  const session = await api.session();
  user.value = session.user;
  setCsrf(session.user.csrf);
  await refreshConversations();
  connectSocket();
}

async function doLogout() {
  await api.logout();
  user.value = null;
  conversations.value = [];
  activeId.value = null;
  socket?.close();
}

async function refreshConversations() {
  const data = await api.conversations();
  conversations.value = data.items;
}

async function openConversation(id: number) {
  activeId.value = id;
  messagePage.value = 1;
  messages.value = [];
  activeDetail.value = await api.conversation(id);
  await loadMessages();
  joinedConversationId.value = null;
  socketSend({ type: 'join', conversation_id: id });
  const last = messages.value.at(-1);
  if (last) await markRead(last.id);
}

async function loadMessages() {
  if (!activeId.value) return;
  const data = await api.messages(activeId.value, messagePage.value);
  if (messagePage.value === 1) messages.value = data.items;
  else messages.value = [...data.items, ...messages.value];
  messageTotal.value = data.total;
  await nextTick();
  if (messagePage.value === 1 && messageBox.value) {
    messageBox.value.scrollTop = messageBox.value.scrollHeight;
  }
}

async function loadOlder() {
  if (!activeId.value) return;
  const loaded = messages.value.length;
  if (loaded >= messageTotal.value) return;
  messagePage.value += 1;
  const prevHeight = messageBox.value?.scrollHeight ?? 0;
  await loadMessages();
  await nextTick();
  if (messageBox.value) {
    messageBox.value.scrollTop = messageBox.value.scrollHeight - prevHeight;
  }
}

async function sendCurrent() {
  if (!activeId.value || !draft.value.trim()) return;
  const body = draft.value;
  draft.value = '';
  await api.sendMessage(activeId.value, body);
}

async function markRead(messageId: number) {
  if (!activeId.value) return;
  await api.markRead(activeId.value, messageId);
  const conv = conversations.value.find((c) => c.id === activeId.value);
  if (conv) conv.unread_count = 0;
}

function connectSocket() {
  socket?.close();
  if (reconnectTimer) window.clearTimeout(reconnectTimer);
  const scheme = window.location.protocol === 'https:' ? 'wss' : 'ws';
  socket = new WebSocket(`${scheme}://${window.location.host}/api/im/socket`);
  socket.onopen = () => {
    reconnectAttempt = 0;
    if (activeId.value) socketSend({ type: 'join', conversation_id: activeId.value });
  };
  socket.onmessage = (event) => {
    const payload = JSON.parse(event.data);
    if (payload.type === 'message' && payload.data) {
      const msg = payload.data as ChatMessage;
      if (msg.conversation_id === activeId.value) {
        messages.value.push(msg);
        nextTick(() => {
          if (messageBox.value) messageBox.value.scrollTop = messageBox.value.scrollHeight;
        });
        markRead(msg.id);
      }
    }
    if (payload.type === 'joined') joinedConversationId.value = payload.conversation_id;
    if (payload.type === 'conversation.updated' && payload.data) {
      upsertConversation(payload.data as ConversationSummary);
      if (payload.data.id === activeId.value) {
        api.conversation(activeId.value).then((detail) => (activeDetail.value = detail));
      }
    }
    if (payload.type === 'conversation.removed' && payload.data?.id) {
      conversations.value = conversations.value.filter((c) => c.id !== payload.data.id);
      if (activeId.value === payload.data.id) {
        activeId.value = null;
        activeDetail.value = null;
        messages.value = [];
      }
    }
  };
  socket.onclose = () => scheduleReconnect();
}

function scheduleReconnect() {
  reconnectAttempt += 1;
  const delay = Math.min(30000, 1000 * 2 ** Math.min(reconnectAttempt, 5));
  reconnectTimer = window.setTimeout(connectSocket, delay);
}

function socketSend(data: unknown) {
  if (socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify(data));
}

async function searchContacts() {
  const data = await api.contacts(contactQuery.value);
  contactResults.value = data.items;
}

async function submitCreate() {
  if (createType.value === 'direct') {
    if (!directPeer.value) {
      ElMessage.warning('请选择联系人');
      return;
    }
    const created = await api.createConversation({ type: 'direct', member_ids: [directPeer.value] });
    upsertConversation(created);
    createVisible.value = false;
    await openConversation(created.id);
    return;
  }
  if (!createTitle.value.trim() || pickedMembers.value.length < 1) {
    ElMessage.warning('请填写群名并选择成员');
    return;
  }
  const created = await api.createConversation({
    type: 'group',
    title: createTitle.value.trim(),
    member_ids: pickedMembers.value,
  });
  upsertConversation(created);
  createVisible.value = false;
  await openConversation(created.id);
}

async function renameGroup() {
  if (!activeDetail.value || activeDetail.value.type !== 'group') return;
  const { value } = await ElMessageBox.prompt('新的群名称', '重命名', {
    inputValue: activeDetail.value.title ?? '',
  });
  if (!value?.trim()) return;
  activeDetail.value = await api.rename(activeDetail.value.id, value.trim());
  await refreshConversations();
}

async function openAddMembers() {
  if (!activeDetail.value) return;
  await searchContacts();
  addMemberIds.value = [];
  addMemberVisible.value = true;
}

async function confirmAddMembers() {
  if (!activeDetail.value || addMemberIds.value.length === 0) return;
  activeDetail.value = await api.addMembers(activeDetail.value.id, addMemberIds.value);
  addMemberVisible.value = false;
  await refreshConversations();
}

async function leaveGroup() {
  if (!activeDetail.value || !user.value) return;
  await api.removeMember(activeDetail.value.id, user.value.id);
  activeId.value = null;
  await refreshConversations();
}

async function removeMember(memberId: number) {
  if (!activeDetail.value) return;
  await api.removeMember(activeDetail.value.id, memberId);
  activeDetail.value = await api.conversation(activeDetail.value.id);
}

async function dissolveGroup() {
  if (!activeDetail.value) return;
  await ElMessageBox.confirm('确定解散此群？', '解散群聊');
  await api.dissolve(activeDetail.value.id);
  activeId.value = null;
  await refreshConversations();
}

watch(createVisible, (open) => {
  if (open) {
    contactQuery.value = '';
    pickedMembers.value = [];
    directPeer.value = null;
    searchContacts();
  }
});

onMounted(bootstrap);
onUnmounted(() => {
  socket?.close();
  if (reconnectTimer) window.clearTimeout(reconnectTimer);
});
</script>

<template>
  <div v-if="loading" class="center">加载中…</div>
  <div v-else-if="!user" class="login-wrap">
    <el-card class="login-card">
      <h1>Loom 聊天</h1>
      <el-form @submit.prevent="doLogin">
        <el-form-item label="邮箱">
          <el-input v-model="loginForm.email" autocomplete="username" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="loginForm.password" type="password" autocomplete="current-password" show-password />
        </el-form-item>
        <el-button type="primary" native-type="submit" style="width: 100%">登录</el-button>
      </el-form>
    </el-card>
  </div>
  <div v-else class="layout">
    <aside class="sidebar">
      <header class="sidebar-head">
        <strong>{{ user.name }}</strong>
        <el-button link type="danger" @click="doLogout">退出</el-button>
      </header>
      <el-input v-model="search" placeholder="搜索会话" clearable />
      <el-button type="primary" plain style="margin: 8px 0" @click="createVisible = true">发起会话</el-button>
      <div class="conv-list">
        <button
          v-for="item in filteredConversations"
          :key="item.id"
          class="conv-item"
          :class="{ active: item.id === activeId }"
          @click="openConversation(item.id)"
        >
          <div class="conv-title">
            <span>{{ displayTitle(item) }}</span>
            <el-badge v-if="item.unread_count > 0" :value="item.unread_count" />
          </div>
          <p class="preview">{{ item.last_message?.body ?? '暂无消息' }}</p>
          <small>{{ formatTime(item.updated_at) }}</small>
        </button>
      </div>
    </aside>
    <main class="chat-main">
      <template v-if="activeId">
        <header class="chat-head">
          <h2>{{ activeDetail ? displayTitle(activeDetail) : '会话' }}</h2>
        </header>
        <el-button v-if="messageTotal > messages.length" text @click="loadOlder">加载更早消息</el-button>
        <div ref="messageBox" class="messages">
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="msg-row"
            :class="{ mine: msg.user_id === user.id }"
          >
            <div class="bubble">
              <small v-if="activeDetail?.type === 'group' && msg.user_id !== user.id">{{ msg.sender_name }}</small>
              <div>{{ msg.body }}</div>
            </div>
          </div>
        </div>
        <footer class="composer">
          <el-input
            v-model="draft"
            type="textarea"
            :rows="3"
            placeholder="Enter 发送，Shift+Enter 换行"
            @keydown="(e: KeyboardEvent) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendCurrent(); } }"
          />
          <el-button type="primary" @click="sendCurrent">发送</el-button>
        </footer>
      </template>
      <div v-else class="center muted">选择或创建一个会话</div>
    </main>
    <aside v-if="activeDetail" class="member-panel">
      <h3>成员</h3>
      <el-button
        v-if="activeDetail.type === 'group' && activeDetail.members.find((m) => m.id === user!.id)?.role === 'owner'"
        size="small"
        @click="renameGroup"
      >改名</el-button>
      <el-button
        v-if="activeDetail.type === 'group' && activeDetail.members.find((m) => m.id === user!.id)?.role === 'owner'"
        size="small"
        @click="openAddMembers"
      >加人</el-button>
      <el-button
        v-if="activeDetail.type === 'group' && activeDetail.members.find((m) => m.id === user!.id)?.role === 'owner'"
        size="small"
        type="danger"
        @click="dissolveGroup"
      >解散</el-button>
      <el-button
        v-if="activeDetail.type === 'group' && activeDetail.members.find((m) => m.id === user!.id)?.role !== 'owner'"
        size="small"
        type="warning"
        @click="leaveGroup"
      >退群</el-button>
      <ul>
        <li v-for="member in activeDetail.members" :key="member.id">
          {{ member.name }}
          <small>{{ member.role === 'owner' ? '群主' : '成员' }}</small>
          <el-button
            v-if="activeDetail.type === 'group' && member.id !== user!.id && activeDetail.members.find((m) => m.id === user!.id)?.role === 'owner'"
            link
            type="danger"
            @click="removeMember(member.id)"
          >移除</el-button>
        </li>
      </ul>
    </aside>
  </div>

  <el-dialog v-model="createVisible" title="新建会话" width="520px">
    <el-radio-group v-model="createType">
      <el-radio-button label="direct">私聊</el-radio-button>
      <el-radio-button label="group">群聊</el-radio-button>
    </el-radio-group>
    <el-input v-model="contactQuery" placeholder="搜索联系人" style="margin: 12px 0" @input="searchContacts" />
    <el-select
      v-if="createType === 'direct'"
      v-model="directPeer"
      filterable
      placeholder="选择联系人"
      style="width: 100%"
    >
      <el-option v-for="c in contactResults" :key="c.id" :label="`${c.name} (${c.email})`" :value="c.id" />
    </el-select>
    <template v-else>
      <el-input v-model="createTitle" placeholder="群名称" style="margin-bottom: 8px" />
      <el-select v-model="pickedMembers" multiple filterable placeholder="选择成员" style="width: 100%">
        <el-option v-for="c in contactResults" :key="c.id" :label="`${c.name} (${c.email})`" :value="c.id" />
      </el-select>
    </template>
    <template #footer>
      <el-button @click="createVisible = false">取消</el-button>
      <el-button type="primary" @click="submitCreate">创建</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="addMemberVisible" title="添加群成员" width="480px">
    <el-select v-model="addMemberIds" multiple filterable placeholder="选择成员" style="width: 100%">
      <el-option
        v-for="c in contactResults.filter((item) => !activeDetail?.members.some((m) => m.id === item.id))"
        :key="c.id"
        :label="`${c.name} (${c.email})`"
        :value="c.id"
      />
    </el-select>
    <template #footer>
      <el-button @click="addMemberVisible = false">取消</el-button>
      <el-button type="primary" @click="confirmAddMembers">添加</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.login-wrap {
  min-height: 100%;
  display: grid;
  place-items: center;
}
.login-card {
  width: 380px;
}
.layout {
  display: grid;
  grid-template-columns: 280px 1fr 240px;
  height: 100vh;
}
.sidebar,
.chat-main,
.member-panel {
  background: #fff;
  border-right: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.sidebar {
  padding: 12px;
}
.sidebar-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.conv-list {
  overflow: auto;
  flex: 1;
}
.conv-item {
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 10px;
  border-bottom: 1px solid #f0f0f0;
  cursor: pointer;
}
.conv-item.active {
  background: #eef5ff;
}
.conv-title {
  display: flex;
  justify-content: space-between;
  font-weight: 600;
}
.preview {
  margin: 4px 0;
  color: #6b7280;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.chat-main {
  border-right: 0;
}
.chat-head {
  padding: 12px 16px;
  border-bottom: 1px solid #eee;
}
.messages {
  flex: 1;
  overflow: auto;
  padding: 16px;
}
.msg-row {
  display: flex;
  margin-bottom: 10px;
}
.msg-row.mine {
  justify-content: flex-end;
}
.bubble {
  max-width: 70%;
  background: #f3f4f6;
  border-radius: 10px;
  padding: 8px 12px;
}
.msg-row.mine .bubble {
  background: #dbeafe;
}
.composer {
  border-top: 1px solid #eee;
  padding: 12px;
  display: grid;
  gap: 8px;
}
.member-panel {
  padding: 12px;
}
.center {
  display: grid;
  place-items: center;
  min-height: 100vh;
}
.muted {
  color: #6b7280;
}
</style>
