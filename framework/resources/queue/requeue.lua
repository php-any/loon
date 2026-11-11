redis.call('XACK', KEYS[1], 'loom', ARGV[1])
redis.call('XDEL', KEYS[1], ARGV[1])
if tonumber(ARGV[3]) > tonumber(ARGV[4]) then
    redis.call('ZADD', KEYS[2], ARGV[3], ARGV[2])
else
    redis.call('XADD', KEYS[1], '*', 'payload', ARGV[2])
end
return 1
