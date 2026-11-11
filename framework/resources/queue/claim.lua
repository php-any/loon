local members = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[4], 'LIMIT', 0, ARGV[5])
for i, member in ipairs(members) do
    redis.call('XADD', KEYS[1], '*', 'payload', member)
    redis.call('ZREM', KEYS[2], member)
end
local recovered = redis.call('XAUTOCLAIM', KEYS[1], 'loom', ARGV[1], ARGV[2], ARGV[3], 'COUNT', 1)
local entries = recovered[2]
local next_cursor = recovered[1]
if #entries == 0 then
    local streams = redis.call('XREADGROUP', 'GROUP', 'loom', ARGV[1], 'COUNT', 1, 'STREAMS', KEYS[1], '>')
    if not streams then
        return {next_cursor}
    end
    entries = streams[1][2]
end
if #entries == 0 then
    return {next_cursor}
end
local entry = entries[1]
return {next_cursor, entry[1], entry[2][2]}
