-- 回收 MySQL 接受租约之前被遗弃的投递，再消费新记录。
local recovered = redis.call('XAUTOCLAIM', KEYS[1], 'loom', 'workers', 60000, ARGV[2], 'COUNT', 1)
local entries = recovered[2]
if #entries == 0 then
    local streams = redis.call('XREADGROUP', 'GROUP', 'loom', 'workers', 'COUNT', 1, 'STREAMS', KEYS[1], '>')
    if not streams then return {recovered[1]} end
    entries = streams[1][2]
end
local entry = entries[1]
redis.call('HSET', KEYS[3], entry[1], ARGV[1])
return {recovered[1], entry[1], entry[2][2]}
