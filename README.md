# Redis 持久化布隆过滤器示例

采用原生 Redis String bitmap + Lua + PHP ext-redis，不要求 BF.* 扩展。位数组、参数和就绪状态均保存在 Redis，PHP 不保存一份独立过滤器。宿主机只需要 Docker 和 Docker Compose v2，不需要安装 PHP、Composer 或 MySQL。

默认使用 Docker Official Images 的 `redis:alpine` 与 `php:cli-alpine`，跟随最新稳定版，不使用 RC 镜像或私有仓库。PHP 工具镜像由本仓库 Dockerfile 构建，安装 ext-redis 6.3.0 和 pdo_mysql；不依赖 Hyperf、Swoole 或其他业务框架。

本例以完整性优先：过滤器没有完整初始化、参数不匹配、key 丢失或 Redis 重启后，判断一律返回 UNKNOWN，不能据此拦截正常数据。首次构建及每次重建需要暂停并排空所有新增、恢复数据的写入；查询可以继续受控回源。这是便于验证的保守方案，不是在线无停写重建方案。

## 1. 文件职责

| 文件 | 内容 |
| --- | --- |
| redis.conf / compose.yaml | AOF/RDB、数据卷、仅宿主机回环端口、noeviction、PHP 工具容器 |
| Dockerfile / .env.example | 官方 PHP 基础镜像、扩展安装和可选的镜像/端口配置 |
| bloom.lua | 初始化、装载、发布、增量写入和三态判断 |
| Bloom.php | PHP 调用及固定 SHA-256 双哈希映射 |
| demo.php / ids.txt | 可直接执行的 CLI 演示及初始 ID |
| initialize-from-db.example.php | 从 MySQL users.id 分页装载的集成片段 |
| create-user.example.php | DB 提交前增量置位的集成片段 |
| tests/run.sh / tests/integration.php | 隔离运行的集成测试，包含真实 Redis 重启和恢复 |
| .github/workflows/ci.yml | 推送及 Pull Request 时执行相同的容器验证 |

## 2. 启动持久化 Redis

在本目录执行：

```bash
docker compose pull redis
docker compose build --pull php
docker compose up -d --wait redis
docker compose exec redis redis-cli PING
# PONG
```

PHP 仅在运行 CLI 时启动，Redis 健康检查通过后才执行 PHP 命令。首次构建会从公开软件源下载扩展和编译依赖，需要网络连接。

演示端口为宿主机 127.0.0.1:6389，数据保存在 bloom-data 命名卷。普通容器重建可以复用该卷，不要使用 `docker compose down -v` 删除演示数据。结束演示使用 `docker compose down`，保留卷中的数据。

镜像标签会随官方稳定版更新，更新时重新执行上述拉取和构建命令。需要固定版本或修改端口时，可复制 `.env.example` 为 `.env` 后修改：

```dotenv
REDIS_IMAGE=redis:8.10.3-alpine
PHP_BASE_IMAGE=php:8.5.12-cli-alpine
REDIS_BIND_PORT=6389
```

以上固定版本是 2026-11-10 核对的稳定版；默认配置使用浮动稳定标签。`PHP_BASE_IMAGE` 需要选择 Alpine CLI 变体。仅支持 64 位 PHP，32 位环境会明确拒绝运行。

redis.conf 关键配置：

```conf
appendonly yes
appendfsync everysec
save 900 1
save 300 100
maxmemory-policy noeviction
```

本地演示设置 `protected-mode no`，允许同一 Docker 网络内的 PHP 连接；Compose 将宿主机端口限制在 `127.0.0.1`。本例不设置密码，容器网络也必须作为可信边界；不要直接把端口绑定到公网。

AOF 每秒刷盘不代表故障时绝无数据丢失；RDB 也是时间点快照。noeviction 防止因内存策略自动淘汰过滤器，但内存不足时会拒绝写入，业务必须处理失败。此配置是本地演示配置，实际部署还应按已有环境配置认证、网络和监控。

## 3. 全量初始化

以下命令在临时 PHP 容器执行，使用上一步构建的公开基础镜像及扩展，不接入业务数据库。`docker compose run --rm` 执行完会自动删除临时 PHP 容器。

如果自行使用其他 PHP 容器，容器内的 127.0.0.1 指向自身，应通过 REDIS_HOST/REDIS_PORT 指向实际可达的演示 Redis 地址。

```bash
docker compose run --rm -T php demo.php check 1001
# UNKNOWN：过滤器尚不存在。

docker compose run --rm -T php demo.php init < ids.txt
# READY, input rows: 3

docker compose run --rm -T php demo.php check 1001
# MAYBE：仍需查询真实数据。

docker compose run --rm -T php demo.php check definitely-not-in-db
# 示例数据下返回 ABSENT；一般情况下未插入元素也可能误判为 MAYBE。
```

初始化流程：

```text
暂停并排空所有新增/恢复写入
→ state = building，创建全零位图，记录本次 build token
→ 从数据库完整扫描有效 ID，逐个计算并写入 7 个位置
→ 全部成功后 state = ready
→ 恢复新增/恢复写入
```

初始化过程中发生异常就不发布 ready，查询返回 UNKNOWN。build token 防止旧构建任务继续装载或发布新构建的数据；本例仍要求同一时刻只运行一个初始化任务。

ids.txt 是数据库完整 ID 集合的演示替身；生产必须完整读取实际需要保护的数据集。initialize-from-db.example.php 假设 users.id 是正整型自增主键，使用主键分页；UUID 主键需要换用与其排序和扫描方式匹配的装载逻辑。不能直接扫描一部分 ID 就发布 ready。

## 4. 增量写入

```bash
docker compose run --rm -T php demo.php add 1003
docker compose run --rm -T php demo.php check 1003
# ADDED / MAYBE
```

CLI 的 add 只演示过滤器写入，不创建数据库用户。实际新增通过 create-user.example.php 所示顺序接入：

```text
开启 DB 事务
→ INSERT，取得 ID，尚未提交
→ 同步 addBeforeCommit(ID)
→ 成功后 COMMIT
```

过滤器写入失败或 UNKNOWN 状态时，本例选择回滚 DB，不允许在过滤器仍可硬拦截的情况下提交一个遗漏的有效 ID。该策略牺牲部分写入可用性；所有新增入口、批量导入和恢复软删除数据的入口都必须接入，绕过入口就不再有完整性保证。

过滤器成功、DB 随后回滚时，只会多保留一个不存在 ID 的标记，增加无效回源，不会遗漏真实数据。Redis 和 DB 没有组成一个分布式事务，Redis 随后重启或数据丢失仍靠恢复保护和重建处理。

查不到的数据若做了空值缓存，创建成功后还应按业务策略失效空值缓存，处理并发回填竞态；本示例未实现业务缓存。

标准布隆过滤器删除数据时不清位；定期重建回收过期标记。位图、哈希算法或 m/k 改变时也需要全量重建，不能原地换参数后继续使用旧数据。

## 5. 三态判断

| 返回值 | 含义 | 缓存未命中后的处理 |
| --- | --- | --- |
| ABSENT / 0 | 完整、就绪过滤器中至少一位为 0 | 可直接判定不存在 |
| MAYBE / 1 | 全部哈希位置为 1 | 查询 DB 确认，可能是假阳性 |
| UNKNOWN / 2 | 不可用、未就绪、参数不符或本次 Redis 进程未完成构建 | 限流、限并发回源 DB，不能当成不存在 |

应用层伪代码：

```php
// 先校验 ID 格式和权限，再查询业务缓存；仅缓存未命中时走这里。
// Redis 连接建立失败也必须由上层捕获并按 UNKNOWN 处理。
$result = $bloom->check($id);
if ($result === Bloom::ABSENT) {
    return null;
}
// MAYBE / UNKNOWN 都回源；UNKNOWN 期间要对数据库保护限流。
return $repository->findById($id);
```

Lua 将检查就绪状态和读 7 个位置放在同一次执行中，防止重建在这两步之间穿插。脚本要求 Redis INFO 权限；权限或类型错误在 PHP 判断层转换为 UNKNOWN。Lua 执行原子不等于失败回滚，因此写入前先检查参数，写入失败后初始化不得发布 ready。

## 6. 持久化恢复演示

```bash
docker compose restart redis

docker compose run --rm -T php demo.php check 1001
# UNKNOWN，即使持久化文件恢复出了旧的 ready 标记。
```

因为 metadata 保存的 run_id 属于旧 Redis 进程，Lua 将它与当前 INFO server 的 run_id 比较，新进程一律拒绝直接硬拦截。

恢复完整集合后才重新启用。演示中先前新增过 1003，因此重建输入必须包含它：

```bash
cat ids.txt > ids-after-create.txt
printf '1003\n' >> ids-after-create.txt
docker compose run --rm -T php demo.php init < ids-after-create.txt
docker compose run --rm -T php demo.php check 1003
# MAYBE
```

真实恢复应重新扫描数据库或使用完整快照加可靠增量追平，不能拿旧 ids.txt 覆盖新增数据。本例为保守恢复方案：AOF 能恢复位数据，但不会仅凭它就认定与数据库已追平。

## 7. 范围与限制

- m=10,000,000 位、k=7，原始位数组约 1.25 MB。预计 100 万元素时，理想均匀哈希模型下误判率约 0.82%；这是估算，不是已实测 SLA。
- 这个教学版每次 Lua 都读取 INFO server，方便观察重启保护，有额外开销；生产需评估性能和容量，并优先考虑成熟过滤器实现。
- 支持本例的单 Redis 主节点，不支持把 Cluster、主从切换或人工 RESTORE 的安全性视为已解决；这些场景需要完善实例/拓扑身份、恢复就绪和可靠增量机制。
- 有状态应用只要连接同一份 Redis，就共享过滤器；不要再给各 PHP worker 建一份不同步的本地过滤器。
- 不要为位图或 metadata 单独设置过期；它们是一组具有完整性要求的数据。noeviction 也不能防止人工删除或备份回退。
- 本例测试持久化文件恢复后的位数组一致性和 UNKNOWN 保护，不代表验证了断电、磁盘故障、数据库事务或真实业务链路。

## 8. 自动验证

在项目根目录执行：

```bash
bash tests/run.sh
```

脚本会拉取 Redis、构建 PHP 镜像，创建独立 Compose 项目、随机宿主机端口和专用数据卷。测试覆盖三态判断、全量/增量写入、重复与中文 ID、扫描中断、过期构建 token、参数不匹配、key 丢失或类型错误、Lua 非法输入、CLI 操作，以及 Redis 真实重启后的位图一致性和 UNKNOWN 保护。

成功时末尾输出 `PASS: container integration suite completed`。测试失败返回非零退出码并输出 Redis 日志；退出时删除本次测试容器与数据卷。测试不会复用或删除日常演示的 bloom-data 卷。请通过脚本运行测试，直接运行 `tests/integration.php` 会被安全开关拒绝。

GitHub Actions 在每次 push、Pull Request 或手动触发时运行同一脚本。[VALIDATION.txt](VALIDATION.txt) 记录最近一次本地验证的环境和范围；真实 MySQL 事务、断电恢复、故障切换仍需要业务环境的专项验证，两个数据库示例文件是集成片段。

## 9. 参考资料

- [Docker 官方 Redis 镜像](https://hub.docker.com/_/redis)
- [Docker 官方 PHP 镜像及扩展安装方式](https://hub.docker.com/_/php)
- [PECL Redis 扩展](https://pecl.php.net/package/redis)

- [Redis SETBIT](https://redis.io/docs/latest/commands/setbit/)
- [Redis Lua](https://redis.io/docs/latest/develop/programmability/eval-intro/)
- [Redis 持久化](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/)
- [原生 BF.* 方案的接口说明](https://redis.io/docs/latest/develop/data-types/probabilistic/bloom-filter/)
