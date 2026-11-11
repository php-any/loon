local function ensure_group(stream, group)
    local result = redis.pcall('XGROUP', 'CREATE', stream, group, '0', 'MKSTREAM')
    if type(result) == 'table' and result.err and not string.find(result.err, 'BUSYGROUP', 1, true) then
        return redis.error_reply(result.err)
    end
    return 1
end
ensure_group(KEYS[1], 'loom')
redis.pcall('XGROUP', 'CREATE', KEYS[3], 'loom-dead', '0', 'MKSTREAM')
return 1
