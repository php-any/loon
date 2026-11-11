if tonumber(ARGV[2]) > tonumber(ARGV[3]) then
    redis.call('ZADD', KEYS[2], ARGV[2], ARGV[1])
    return 'delayed'
end
return redis.call('XADD', KEYS[1], '*', 'payload', ARGV[1])
