redis.call('XACK', KEYS[1], 'loom', ARGV[1])
redis.call('XDEL', KEYS[1], ARGV[1])
redis.call('XADD', KEYS[3], '*', 'payload', ARGV[2])
return 1
