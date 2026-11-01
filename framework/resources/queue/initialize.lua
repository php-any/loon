local result = redis.pcall('XGROUP', 'CREATE', KEYS[1], 'loom', '0', 'MKSTREAM')
if type(result) == 'table' and result.err and not string.find(result.err, 'BUSYGROUP', 1, true) then
    return redis.error_reply(result.err)
end
return 1
