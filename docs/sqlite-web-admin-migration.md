# SQLite 升级与本地验证

本次范围是旧 SQLite 客户端 API 的修正与简单 Web 用户管理。保留 PHP + SQLite，不引入 MySQL、Go、Vue 构建链。Dockerfile、Compose 和 Nginx 配置不变，本轮不启动 Docker、不构建或上传镜像；后续由 GHCR 工作流构建。

## 数据迁移与回退原则

新旧容器必须使用两个独立数据目录。保留旧容器和原数据库，测试新版本时只挂载一致性副本。不要同时写同一个 SQLite 文件。

不要运行中只复制 `rustdesk.db`：WAL 模式还可能存在未 checkpoint 的内容。仓库提供 Python 标准库工具，通过 SQLite backup API 生成一致性快照：

```sh
python3 tools/sqlite_snapshot.py /path/to/source/rustdesk.db /path/outside/repo/test-data/rustdesk.db
```

工具只读打开源库，目标拒绝覆盖；校验完整性，输出各表行数及 SHA-256，不输出用户或设备内容。数据库、备份和凭据应放在 Git 仓库和 Web 根目录之外。

迁移首次运行应保留旧四表的所有原字段值，包括用户 ID、用户名、旧密码摘要、创建时间、删除时间、标签、设备字段、hash 及 token；新增字段/表承载管理功能。旧密码正确登录后才升级存储格式。旧库中已经损坏的 hash 不能凭空恢复。

### 测试阶段

1. 确定旧服务实际使用的数据库文件，制作快照并记录校验结果。
2. 将快照下载到本地仓库外，保留一份不运行应用的基线，再复制为测试库。
3. 首次迁移后逐字段比较旧四表，检查重复运行不改变记录。
4. 用测试库验证登录、地址簿读取/写入和用户管理。
5. 测试数据不回写生产。旧容器继续使用原数据库。

### 正式切换和回滚

正式切换时停止旧服务写入，重新取得最终一致性快照，再启动新服务。不能把数日前的测试副本直接当作最新生产数据。

新服务一旦发生改密、账号变更或地址簿写入，原库和新库就会分叉。密码升级后旧代码不能直接验证新格式，所以回滚使用原库，不应让旧容器直接打开升级后的新库。回滚前保存新库快照；如果必须保留切换后的所有写入，需要单独的差异合并/迁移验证，不能承诺“启用旧容器”自动做到零数据损失。

## 管理范围

`/admin` 提供管理员登录、查询/搜索/分页、新增用户、修改用户名和密码、启停账号、删除用户。删除采用软删除并保留地址簿记录；停用、改密及删除应撤销对应会话。

管理员会话和 RustDesk Bearer token 分离，管理写操作校验 CSRF。新安装不开放默认弱密码账号，已有账号也不能仅因用户名为 `admin` 就自动提升；通过本地 CLI 显式建立/指定管理员。

## 客户端兼容范围

保留 `/api/login`、`currentUser`、`logout`、`users`、`peers`、旧 `/api/ab`、`login-options`、`sysinfo`、`heartbeat` 和连接审计。`/api/ab` 继续使用双层 JSON；不要把新版 `/api/ab/personal` 提前返回 guid，否则客户端会切换到尚未启用的新版协议。

设备无登录上报不代表设备所有权。Web 用户禁用阻止 API 登录与地址簿访问，不等于强制阻断 hbbs 或直接远程连接。

## 本次真实数据验证结果

通过 Termark 保存的 `NTServer-SH` 连接，从 `/opt/1panel/apps/rustdeskapi/data/rustdesk.db` 使用 SQLite backup API 取得一致性快照。未启动、停止或修改服务器容器，未修改原数据库。

本地只读基线：`/Users/olly/.local/share/rustdesk-api-tests/nt-20260929/baseline.db`。

- 大小 24,576 字节，SHA-256 `a2a26ac70a6cffd20532b1ef71d13e7ffd0aa49349812bc9018c9e26291a5108`，与远端快照一致。
- 2 个用户、5 条设备、1 条标签、34 条 token。
- 在另一份 `migration-test.db` 上执行首次和重复迁移，旧四表所有原字段逐行一致，完整性检查 `ok`。
- 使用副本中可用的旧 token 验证了 1 个用户的地址簿 HTTP 读取，设备字段/hash/标签与库中记录一致；没有输出秘密字段。
- 原基线保持只读，验证后校验值不变。
- 这证明本次快照的入站迁移和地址簿读取保真，不代表新旧服务双写或切换后的逆向合并已经实现。

本地使用 FrankenPHP 内置 PHP 8.5.11（有 SQLite3）验证，运行时在仓库外。旧镜像基于 PHP 8.3，遵照本轮不启用 Docker 的要求，容器及真实客户端远控联调留给后续构建后的测试。

## 常用管理命令

```sh
# 只迁移，不赋予任何用户新权限
php sqlite/manage.php --db=/absolute/test/rustdesk.db --migrate
# 显式指定已有用户作为管理员，保留其密码与地址簿，撤销旧会话
php sqlite/manage.php --db=/absolute/test/rustdesk.db --promote-admin=1
# 新安装或管理员密码恢复，通过 stdin 输入密码
php sqlite/manage.php --db=/absolute/test/rustdesk.db --init-admin=admin --password-stdin
php sqlite/manage.php --db=/absolute/test/rustdesk.db --reset-admin=1 --password-stdin
```

`--promote-admin=1` 和 `--reset-admin=1` 中的 ID 必须对应实际选定用户，不能只凭账号名称猜测。新服务首次启动不自动提升任何旧用户。
