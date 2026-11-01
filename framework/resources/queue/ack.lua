-- 过期工作进程不能确认已被其他工作进程重新租用的投递。
if redis.call('HGET', KEYS[3], ARGV[3]) ~= ARGV[2] then return 0 end
redis.call('XACK', KEYS[1], 'loom', ARGV[3])
redis.call('XDEL', KEYS[1], ARGV[3])
redis.call('HDEL', KEYS[3], ARGV[3])
if redis.call('HGET', KEYS[2], ARGV[1]) == ARGV[4] .. '|' .. ARGV[3] then
    redis.call('HDEL', KEYS[2], ARGV[1])
end
return 1
