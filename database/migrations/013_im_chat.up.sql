ALTER TABLE im_conversation_members ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'member' CHECK(role IN ('owner','member'));
UPDATE im_conversation_members m
INNER JOIN (
    SELECT conversation_id, user_id FROM (
        SELECT m.conversation_id, m.user_id, ROW_NUMBER() OVER (PARTITION BY m.conversation_id ORDER BY m.joined_at ASC, m.user_id ASC) AS rn
        FROM im_conversation_members m
        INNER JOIN im_conversations c ON c.id = m.conversation_id AND c.type = 'group'
    ) ranked WHERE rn = 1
) owners ON owners.conversation_id = m.conversation_id AND owners.user_id = m.user_id
SET m.role = 'owner';
