local function ensure_group(stream, group)
    local result = redis.pcall('XGROUP', 'CREATE', stream, group, '0', 'MKSTREAM')
    if type(result) == 'table' and result.err and not string.find(result.err, 'BUSYGROUP', 1, true) then
        return nil, result.err
    end
    return true
end
local ok, err = ensure_group(KEYS[1], 'loom')
if not ok then
    return redis.error_reply(err)
end
local dead = redis.pcall('XGROUP', 'CREATE', KEYS[3], 'loom-dead', '0', 'MKSTREAM')
if type(dead) == 'table' and dead.err and not string.find(dead.err, 'BUSYGROUP', 1, true) then
    return redis.error_reply(dead.err)
end
return 1
