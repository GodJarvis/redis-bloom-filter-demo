-- KEYS[1]: bitmap; KEYS[2]: metadata. Both keys must use the same hash tag.
-- ARGV: action, m, k, algorithm, build-token, zero or more bit positions.
local action = ARGV[1]
local m = tonumber(ARGV[2])
local k = tonumber(ARGV[3])
local algorithm = ARGV[4]
local token = ARGV[5]
if not m or m < 1 or m > 100000000 or m ~= math.floor(m)
    or not k or k < 1 or k > 32 or k ~= math.floor(k) then
    return redis.error_reply('invalid bloom parameters')
end

-- Intentionally conservative: a restored READY flag is invalid after restart.
-- INFO permission is required. This demo targets a standalone Redis primary.
local runId = string.match(redis.call('INFO', 'server'), 'run_id:([^\r\n]+)')
if not runId then
    return redis.error_reply('cannot determine Redis run_id')
end

if action == 'init' then
    if not token or token == '' then
        return redis.error_reply('missing build token')
    end
    redis.call('HSET', KEYS[2], 'state', 'building', 'run_id', runId,
        'm', ARGV[2], 'k', ARGV[3], 'algorithm', algorithm, 'token', token)
    redis.call('DEL', KEYS[1])
    redis.call('SETBIT', KEYS[1], m - 1, 0)
    return 1
end

local valid = redis.call('HGET', KEYS[2], 'run_id') == runId
    and redis.call('HGET', KEYS[2], 'm') == ARGV[2]
    and redis.call('HGET', KEYS[2], 'k') == ARGV[3]
    and redis.call('HGET', KEYS[2], 'algorithm') == algorithm
    and redis.call('EXISTS', KEYS[1]) == 1

if valid then
    valid = redis.call('STRLEN', KEYS[1]) == math.ceil(m / 8)
end
local state = redis.call('HGET', KEYS[2], 'state')

if action == 'check' and (not valid or state ~= 'ready') then
    return 2 -- UNKNOWN: bypass the filter and perform a rate-limited DB lookup.
end
if not valid then
    return redis.error_reply('filter unavailable: rebuild before writing')
end

if action == 'publish' or action == 'load' then
    if state ~= 'building' or redis.call('HGET', KEYS[2], 'token') ~= token then
        return redis.error_reply('build token/state mismatch')
    end
elseif action == 'add' then
    if state ~= 'ready' then
        return redis.error_reply('filter not ready: do not commit new DB row')
    end
elseif action ~= 'check' then
    return redis.error_reply('unknown action')
end

if action == 'publish' then
    redis.call('HSET', KEYS[2], 'state', 'ready')
    return 1
end

if #ARGV ~= 5 + k then
    return redis.error_reply('wrong number of bit positions')
end
-- Validate the complete input before writing: Lua errors do not roll back writes.
for i = 6, #ARGV do
    local position = tonumber(ARGV[i])
    if not position or position < 0 or position >= m
        or position ~= math.floor(position) then
        return redis.error_reply('invalid bit position')
    end
end

if action == 'check' then
    for i = 6, #ARGV do
        if redis.call('GETBIT', KEYS[1], tonumber(ARGV[i])) == 0 then
            return 0 -- ABSENT, only while the filter is complete and ready.
        end
    end
    return 1 -- MAYBE; always confirm against cache/DB.
end

for i = 6, #ARGV do
    redis.call('SETBIT', KEYS[1], tonumber(ARGV[i]), 1)
end
return 1
