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

    public function assertMember(int $conversationId, int $userId): void
    {
        $rows = ConversationMember::whereConversationId($conversationId)->whereUserId($userId)->rows(['user_id']);
        if ($rows === []) {
            throw new HttpError(403, '不是此会话成员');
        }
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
            $items[] = $row->toArray();
        }
        return ['items' => $items];
    }

    public function messages(int $conversationId, int $userId, int $page): array
    {
        $this->assertMember($conversationId, $userId);
        $limit = 50;
        $offset = (max(1, min(100000, $page)) - 1) * $limit;
        $items = [];
        foreach (Message::whereConversationId($conversationId)->native()
            ->orderBy('id DESC')->limit($limit)->offset($offset)->get() as $message) {
            $items[] = $message->toArray();
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
        return $this->database->transaction(function () use ($type, $title, $members): array {
            if ($type === 'direct') {
                $lock = 'loom:im:direct:' . $members[0] . ':' . $members[1];
                $acquired = (int)($this->database->query('SELECT GET_LOCK(?, 10) AS acquired', [$lock])[0]['acquired'] ?? 0);
                if ($acquired !== 1) {
                    throw new HttpError(503, '会话创建繁忙，请稍后重试');
                }
                try {
                    $existing = $this->findDirectConversation($members[0], $members[1]);
                    if ($existing !== null) {
                        return $existing;
                    }
                    return $this->persistConversation($type, $title, $members);
                } finally {
                    $this->database->query('SELECT RELEASE_LOCK(?)', [$lock]);
                }
            }
            return $this->persistConversation($type, $title, $members);
        });
    }

    /**
     * @param list<int> $members
     */
    private function persistConversation(string $type, ?string $title, array $members): array
    {
        $now = time();
        $conversation = Conversation::create([
            'type' => $type,
            'title' => $title,
            'updated_at' => $now,
        ]);
        foreach ($members as $memberId) {
            ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $memberId]);
        }
        return $conversation->toArray();
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
        $payload = ['type' => 'message', 'data' => $message->toArray()];
        $this->hub->send(self::conversationChannel($conversationId), $payload);
        $this->monitoring?->recordEvent('im.message.sent', [
            'conversation_id' => $conversationId,
            'message_id' => $message->id,
            'user_id' => $userId,
        ]);
        return $message->toArray();
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
}
