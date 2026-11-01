-- 每个持久化执行尝试仅发布一次。替换过期尝试时也清理其 PEL 记录。
local previous = redis.call('HGET', KEYS[2], ARGV[1])
if previous then
    local attempt, id = string.match(previous, '^(%d+)|(.+)$')
    if #redis.call('XRANGE', KEYS[1], id, id) > 0 then
        if tonumber(attempt) >= tonumber(ARGV[2]) then return id end
        redis.call('XACK', KEYS[1], 'loom', id)
        redis.call('XDEL', KEYS[1], id)
        redis.call('HDEL', KEYS[3], id)
    end
end
local id = redis.call('XADD', KEYS[1], '*', 'payload', ARGV[3])
redis.call('HSET', KEYS[2], ARGV[1], ARGV[2] .. '|' .. id)
return id
