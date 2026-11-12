<?php

declare(strict_types=1);

namespace App\Services\Im;

use App\Models\Im\Conversation;
use App\Models\Im\ConversationMember;
use App\Models\Im\Message;
use Loom\HttpError;
use Loom\WebSocket\Hub;

#[\Container\Annotation\Singleton]
final class ImMessaging
{
    private \Loom\Database $database;
    private Hub $hub;
    private ?\Loom\Monitoring\Monitoring $monitoring;

    public function __construct(\Loom\Database $database, Hub $hub, ?\Loom\Monitoring\Monitoring $monitoring = null)
    {
        $this->database = $database;
        $this->hub = $hub;
        $this->monitoring = $monitoring;
    }

    public static function conversationChannel(int $conversationId): string
    {
        return 'im:conversation:' . $conversationId;
    }

    public static function userChannel(int $userId): string
    {
        return 'im:user:' . $userId;
    }

    public function assertMember(int $conversationId, int $userId): array
    {
        $rows = ConversationMember::whereConversationId($conversationId)->whereUserId($userId)->rows(['user_id', 'role']);
        if ($rows === []) {
            throw new HttpError(403, '不是此会话成员');
        }
        return $rows[0];
    }

    public function contacts(int $actorId, string $query): array
    {
        $query = trim($query);
        $like = '%' . $query . '%';
        $sql = <<<'SQL'
SELECT u.id, u.name, u.email
FROM loom_users u
LEFT JOIN admin_accounts a ON a.user_id = u.id
WHERE u.id <> ?
  AND (a.enabled IS NULL OR a.enabled = 1)
SQL;
        $params = [$actorId];
        if ($query !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY u.id ASC LIMIT 25';
        $items = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $items[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email']];
        }
        return ['items' => $items];
    }

    public function conversations(int $userId): array
    {
        $items = [];
        foreach ($this->database->bind(\Database\DB::model(\App\Queries\Im\ConversationRow::class, $this->database->modelConnectionName())
            ->table('im_conversation_members m')
            ->join('JOIN im_conversations c ON c.id=m.conversation_id')
            ->where('m.user_id=?', $userId)
            ->select('c.id,c.type,c.title,c.updated_at,m.last_read_message_id')
            ->orderBy('c.updated_at DESC'))->get() as $row) {
            $items[] = $this->summarizeConversation((int)$row->id, $userId, $row->toArray());
        }
        return ['items' => $items];
    }

    public function conversation(int $conversationId, int $userId): array
    {
        $this->assertMember($conversationId, $userId);
        $conversation = Conversation::whereId($conversationId)->first();
        if ($conversation === null) {
            throw new HttpError(404, '会话不存在');
        }
        $summary = $this->summarizeConversation($conversationId, $userId, $conversation->toArray());
        $members = [];
        foreach ($this->database->query(
            'SELECT m.user_id, m.role, u.name FROM im_conversation_members m JOIN loom_users u ON u.id=m.user_id WHERE m.conversation_id=? ORDER BY m.joined_at ASC',
            [$conversationId],
        ) as $row) {
            $members[] = ['id' => (int)$row['user_id'], 'name' => $row['name'], 'role' => $row['role']];
        }
        $summary['members'] = $members;
        return $summary;
    }

    /**
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function summarizeConversation(int $conversationId, int $userId, array $base): array
    {
        $last = $this->database->query(
            'SELECT m.id, m.user_id, m.body, m.created_at, u.name AS sender_name FROM im_messages m JOIN loom_users u ON u.id=m.user_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 1',
            [$conversationId],
        );
        $lastMessage = $last === [] ? null : [
            'id' => (int)$last[0]['id'],
            'user_id' => (int)$last[0]['user_id'],
            'sender_name' => $last[0]['sender_name'],
            'body' => $last[0]['body'],
            'created_at' => (int)$last[0]['created_at'],
        ];
        $member = ConversationMember::whereConversationId($conversationId)->whereUserId($userId)->rows(['last_read_message_id']);
        $lastRead = $member === [] ? null : $member[0]['last_read_message_id'];
        $unreadSql = 'SELECT COUNT(*) AS total FROM im_messages WHERE conversation_id=?';
        $unreadParams = [$conversationId];
        if ($lastRead !== null) {
            $unreadSql .= ' AND id>?';
            $unreadParams[] = (int)$lastRead;
        }
        $unread = (int)($this->database->query($unreadSql, $unreadParams)[0]['total'] ?? 0);
        $type = (string)($base['type'] ?? '');
        $peer = null;
        $membersCount = null;
        if ($type === 'direct') {
            $rows = $this->database->query(
                'SELECT u.id, u.name FROM im_conversation_members m JOIN loom_users u ON u.id=m.user_id WHERE m.conversation_id=? AND m.user_id<>? LIMIT 1',
                [$conversationId, $userId],
            );
            if ($rows !== []) {
                $peer = ['id' => (int)$rows[0]['id'], 'name' => $rows[0]['name']];
            }
        } else {
            $membersCount = (int)($this->database->query('SELECT COUNT(*) AS total FROM im_conversation_members WHERE conversation_id=?', [$conversationId])[0]['total'] ?? 0);
        }
        return [
            'id' => $conversationId,
            'type' => $type,
            'title' => $base['title'] ?? null,
            'updated_at' => (int)($base['updated_at'] ?? 0),
            'last_read_message_id' => $lastRead !== null ? (int)$lastRead : null,
            'peer' => $peer,
            'members_count' => $membersCount,
            'last_message' => $lastMessage,
            'unread_count' => $unread,
        ];
    }

    public function messages(int $conversationId, int $userId, int $page): array
    {
        $this->assertMember($conversationId, $userId);
        $limit = 50;
        $offset = (max(1, min(100000, $page)) - 1) * $limit;
        $items = [];
        foreach ($this->database->query(
            'SELECT m.id, m.conversation_id, m.user_id, m.body, m.created_at, u.name AS sender_name FROM im_messages m JOIN loom_users u ON u.id=m.user_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$conversationId],
        ) as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'conversation_id' => (int)$row['conversation_id'],
                'user_id' => (int)$row['user_id'],
                'body' => $row['body'],
                'created_at' => (int)$row['created_at'],
                'sender_name' => $row['sender_name'],
            ];
        }
        $count = Message::project($this->database, \Loom\Results\CountResult::class)
            ->where('conversation_id=?', $conversationId)->select('COUNT(*) AS total')->first();
        return ['items' => array_reverse($items), 'total' => (int)$count->total];
    }

    /**
     * @param list<int> $memberIds
     */
    public function createConversation(int $actorId, string $type, ?string $title, array $memberIds): array
    {
        if (!in_array($type, ['direct', 'group'], true)) {
            throw new HttpError(422, '无效的会话类型');
        }
        $members = array_values(array_unique(array_merge([$actorId], $memberIds)));
        sort($members);
        if ($type === 'direct' && count($members) !== 2) {
            throw new HttpError(422, '直聊需要恰好两名成员');
        }
        if ($type === 'group' && count($members) < 2) {
            throw new HttpError(422, '群聊至少需要两名成员');
        }
        if ($type === 'group' && ($title === null || trim($title) === '')) {
            throw new HttpError(422, '群聊需要名称');
        }
        return $this->database->transaction(function () use ($type, $title, $members, $actorId): array {
            if ($type === 'direct') {
                $lock = 'loom:im:direct:' . $members[0] . ':' . $members[1];
                $acquired = (int)($this->database->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock])[0]['acquired'] ?? 0);
                if ($acquired !== 1) {
                    throw new HttpError(503, '会话创建繁忙，请稍后重试');
                }
                try {
                    $existing = $this->findDirectConversation($members[0], $members[1]);
                    if ($existing !== null) {
                        return $this->summarizeConversation((int)$existing['id'], $actorId, $existing);
                    }
                    $created = $this->persistConversation($type, $title, $members, $actorId);
                    return $this->summarizeConversation((int)$created['id'], $actorId, $created);
                } finally {
                    $this->database->query('SELECT RELEASE_LOCK(?)', [$lock]);
                }
            }
            $created = $this->persistConversation($type, $title, $members, $actorId);
            $summary = $this->summarizeConversation((int)$created['id'], $actorId, $created);
            $this->broadcastConversationUpdated((int)$created['id']);
            $this->monitoring?->recordEvent('im.conversation.created', ['conversation_id' => (int)$created['id'], 'type' => $type]);
            return $summary;
        });
    }

    /**
     * @param list<int> $members
     * @return array{id: int, type: string, title: ?string, updated_at: int}
     */
    private function persistConversation(string $type, ?string $title, array $members, int $actorId): array
    {
        $now = time();
        $conversation = Conversation::create([
            'type' => $type,
            'title' => $type === 'group' ? trim((string)$title) : $title,
            'updated_at' => $now,
        ]);
        foreach ($members as $memberId) {
            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $memberId,
                'role' => $type === 'group' && $memberId === $actorId ? 'owner' : 'member',
            ]);
        }
        return $conversation->toArray();
    }

    public function renameGroup(int $conversationId, int $actorId, string $title): array
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > 255) {
            throw new HttpError(422, '群名称无效');
        }
        $member = $this->assertMember($conversationId, $actorId);
        $conversation = Conversation::whereId($conversationId)->rows(['type', 'title', 'updated_at']);
        if ($conversation === [] || $conversation[0]['type'] !== 'group') {
            throw new HttpError(422, '只能修改群聊名称');
        }
        if ($member['role'] !== 'owner') {
            throw new HttpError(403, '仅群主可修改群名称');
        }
        Conversation::whereId($conversationId)->native()->update(['title' => $title, 'updated_at' => time()]);
        $this->broadcastConversationUpdated($conversationId);
        $this->monitoring?->recordEvent('im.conversation.renamed', ['conversation_id' => $conversationId, 'user_id' => $actorId]);
        return $this->conversation($conversationId, $actorId);
    }

    /**
     * @param list<int> $memberIds
     */
    public function addMembers(int $conversationId, int $actorId, array $memberIds): array
    {
        $member = $this->assertMember($conversationId, $actorId);
        $conversation = Conversation::whereId($conversationId)->rows(['type']);
        if ($conversation === [] || $conversation[0]['type'] !== 'group') {
            throw new HttpError(422, '只能向群聊添加成员');
        }
        if ($member['role'] !== 'owner') {
            throw new HttpError(403, '仅群主可添加成员');
        }
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds === []) {
            throw new HttpError(422, '请选择要添加的成员');
        }
        $now = time();
        foreach ($memberIds as $memberId) {
            $this->adminActiveOrFail($memberId);
            ConversationMember::insertOrIgnore(['conversation_id' => $conversationId, 'user_id' => $memberId, 'role' => 'member', 'joined_at' => $now], ['conversation_id', 'user_id']);
        }
        Conversation::whereId($conversationId)->native()->update(['updated_at' => $now]);
        $this->broadcastConversationUpdated($conversationId);
        $this->monitoring?->recordEvent('im.conversation.members_added', ['conversation_id' => $conversationId, 'user_id' => $actorId]);
        return $this->conversation($conversationId, $actorId);
    }

    public function removeMember(int $conversationId, int $actorId, int $targetId): array
    {
        $conversation = Conversation::whereId($conversationId)->rows(['type']);
        if ($conversation === []) {
            throw new HttpError(404, '会话不存在');
        }
        if ($conversation[0]['type'] === 'direct') {
            throw new HttpError(422, '直聊不能移除成员');
        }
        $actor = $this->assertMember($conversationId, $actorId);
        if ($targetId === $actorId) {
            if ($actor['role'] === 'owner') {
                throw new HttpError(422, '群主请先解散群聊或转让群主后再退出');
            }
            ConversationMember::whereConversationId($conversationId)->whereUserId($actorId)->delete();
            $this->broadcastConversationRemoved($conversationId, [$actorId]);
            $this->broadcastConversationUpdated($conversationId);
            $this->monitoring?->recordEvent('im.conversation.left', ['conversation_id' => $conversationId, 'user_id' => $actorId]);
            return ['removed' => true, 'left' => true];
        }
        if ($actor['role'] !== 'owner') {
            throw new HttpError(403, '仅群主可移除成员');
        }
        $this->assertMember($conversationId, $targetId);
        ConversationMember::whereConversationId($conversationId)->whereUserId($targetId)->delete();
        Conversation::whereId($conversationId)->native()->update(['updated_at' => time()]);
        $this->broadcastConversationRemoved($conversationId, [$targetId]);
        $this->broadcastConversationUpdated($conversationId);
        $this->monitoring?->recordEvent('im.conversation.member_removed', ['conversation_id' => $conversationId, 'user_id' => $actorId, 'target_id' => $targetId]);
        return $this->conversation($conversationId, $actorId);
    }

    public function dissolve(int $conversationId, int $actorId): array
    {
        $member = $this->assertMember($conversationId, $actorId);
        $conversation = Conversation::whereId($conversationId)->rows(['type']);
        if ($conversation === [] || $conversation[0]['type'] !== 'group') {
            throw new HttpError(422, '只能解散群聊');
        }
        if ($member['role'] !== 'owner') {
            throw new HttpError(403, '仅群主可解散群聊');
        }
        $memberIds = array_map(static fn (array $row): int => (int)$row['user_id'], ConversationMember::whereConversationId($conversationId)->rows(['user_id']));
        $this->database->transaction(function () use ($conversationId): void {
            Message::whereConversationId($conversationId)->delete();
            ConversationMember::whereConversationId($conversationId)->delete();
            Conversation::whereId($conversationId)->delete();
        });
        $this->broadcastConversationRemoved($conversationId, $memberIds);
        $this->monitoring?->recordEvent('im.conversation.dissolved', ['conversation_id' => $conversationId, 'user_id' => $actorId]);
        return ['dissolved' => true];
    }

    public function sendMessage(int $conversationId, int $userId, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new HttpError(422, '消息内容不能为空');
        }
        if (strlen($body) > 10000) {
            throw new HttpError(422, '消息过长');
        }
        $this->assertMember($conversationId, $userId);
        $message = Message::create([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'body' => $body,
        ]);
        Conversation::whereId($conversationId)->native()->update(['updated_at' => time()]);
        $senderName = (string)($this->database->query('SELECT name FROM loom_users WHERE id=?', [$userId])[0]['name'] ?? '');
        $payload = [
            'id' => $message->id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => $message->created_at,
            'sender_name' => $senderName,
        ];
        $this->hub->send(self::conversationChannel($conversationId), ['type' => 'message', 'data' => $payload]);
        $this->broadcastConversationUpdated($conversationId);
        $this->monitoring?->recordEvent('im.message.sent', [
            'conversation_id' => $conversationId,
            'message_id' => $message->id,
            'user_id' => $userId,
        ]);
        return $payload;
    }

    public function markRead(int $conversationId, int $userId, int $messageId): void
    {
        if ($messageId < 1) {
            throw new HttpError(422, '无效的消息');
        }
        $this->assertMember($conversationId, $userId);
        $message = Message::whereId($messageId)->whereConversationId($conversationId)->rows(['id']);
        if ($message === []) {
            throw new HttpError(404, '消息不存在或不属于此会话');
        }
        ConversationMember::whereConversationId($conversationId)->whereUserId($userId)->native()
            ->update(['last_read_message_id' => $messageId]);
        $this->pushUserConversationPreview($conversationId, $userId);
    }

    private function findDirectConversation(int $a, int $b): ?array
    {
        $sql = <<<'SQL'
SELECT c.id FROM im_conversations c
JOIN im_conversation_members m1 ON m1.conversation_id=c.id AND m1.user_id=?
JOIN im_conversation_members m2 ON m2.conversation_id=c.id AND m2.user_id=?
WHERE c.type='direct' LIMIT 1
SQL;
        $rows = $this->database->query($sql, [$a, $b]);
        if ($rows === []) {
            return null;
        }
        $conversation = Conversation::whereId((int)$rows[0]['id'])->first();
        return $conversation?->toArray();
    }

    private function adminActiveOrFail(int $userId): void
    {
        $account = \App\Models\Identity\Account::whereUserId($userId)->rows(['enabled']);
        if ($account !== [] && (int)$account[0]['enabled'] !== 1) {
            throw new HttpError(422, '不能添加已停用的账号');
        }
    }

    private function memberIds(int $conversationId): array
    {
        return array_map(
            static fn (array $row): int => (int)$row['user_id'],
            ConversationMember::whereConversationId($conversationId)->rows(['user_id']),
        );
    }

    private function broadcastConversationUpdated(int $conversationId): void
    {
        foreach ($this->memberIds($conversationId) as $userId) {
            $this->pushUserConversationPreview($conversationId, $userId);
        }
    }

    private function pushUserConversationPreview(int $conversationId, int $userId): void
    {
        if (!in_array($userId, $this->memberIds($conversationId), true)) {
            return;
        }
        $conversation = Conversation::whereId($conversationId)->rows(['id', 'type', 'title', 'updated_at']);
        if ($conversation === []) {
            return;
        }
        $summary = $this->summarizeConversation($conversationId, $userId, $conversation[0]);
        $this->hub->send(self::userChannel($userId), ['type' => 'conversation.updated', 'data' => $summary]);
    }

    /**
     * @param list<int> $userIds
     */
    private function broadcastConversationRemoved(int $conversationId, array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->hub->send(self::userChannel($userId), ['type' => 'conversation.removed', 'data' => ['id' => $conversationId]]);
        }
    }
}
