# RustDesk 在线升级与 Release 发布方案

状态：待确认，本文只记录方案，不代表已经实施。

## 1. 目标

建立一条可回滚、可验证的发布链。客户端安装包优先放在阿里云 OSS，NT 只承载 API、数据库和策略服务：

```text
RustDesk GitHub Actions 构建
  -> GitHub Release 产物
  -> 上传到 Aliyun OSS
  -> 计算文件大小和 SHA-256
  -> Ed25519 签名
  -> 发布 stable manifest 和公钥
  -> 旧客户端检查到更新
  -> 下载、校验、安装、遥测
```

本方案优先修改 API 服务端、发布脚本和 GitHub Actions。当前已经打包的 RustDesk 客户端先不重新修改或出包。

## 2. 已确认的现状

### 2.1 客户端

当前 RustDesk 客户端已经具备：

- 向 `https://rdapi.yan.life/rd/update/v1/check` 发起版本检查。
- 携带 `client_id`、`client_uuid`、`product`、`edition`、`version`、`build_number`、`build_seq`、`channel`、`platform`、`arch`、`distribution`、`install_mode`、`source_commit` 等字段。
- 根据版本号和 `build_seq` 判断是否有新版本。
- 读取内嵌 manifest，按 target key 选择安装包。
- 按 primary 和 mirrors 顺序下载。
- 校验文件大小、SHA-256 和 Ed25519 签名。
- 根据 `disabled`、`notify`、`download`、`auto_install` 模式处理更新。
- 上报 `started`、`downloaded`、`installed`、`failed`、`rolled_back` 事件。

当前已发布客户端构建为 `yan-v1.5.0-build20260930.2`，`build_seq=2026093002`，包含 standard/SOS 的 Windows、macOS ARM、Android 产物。

### 2.2 API

API 已有以下接口：

```text
POST /rd/update/v1/check
GET  /rd/update/v1/manifest/{channel}.json
GET  /rd/update/v1/keys.json
POST /rd/update/v1/events
管理端 release、policy、event 接口
```

API 已有以下表：

```text
update_releases
device_update_policies
device_update_events
```

生产 API `v0.1.6` 已上线，`/rd/update/v1/check` 已经生效。

### 2.3 当前阻塞点

当前生产：

- `GET /rd/update/v1/manifest/stable.json` 尚未发布，返回“更新清单不存在”。
- GitHub Release 产物还没有自动上传到 NT。
- Ed25519 公钥配置目前为空或未完成正式配置。
- 生产 Compose 的 releases 挂载没有和仓库历史约定对齐。
- 升级事件表没有完整保存客户端上报的所有版本和构建元数据。

## 3. 历史目录与 Nginx 约定

历史提交 `4a052cb` 曾经明确包含：

```nginx
location = /update { return 404; }
location = /update/ { return 404; }

location /update/files/ {
    alias /var/www/releases/;
    autoindex off;
    add_header X-Content-Type-Options nosniff always;
}
```

含义：

```text
/update       -> 404
/update/      -> 404
/update/files/ -> 不显示目录列表
/update/files/<精确文件路径> -> 允许下载
```

当前 main 中保留了 `/update/files/` 和 `autoindex off`，但两个精确 404 location 需要恢复。

## 4. 存储方案与地区选择

### 4.1 推荐方案：OSS 主存储

GitHub Actions 自动上传到 OSS 比直接上传 NT 更合适：

- 不需要在 GitHub Secrets 中长期保存 NT SSH 私钥。
- 发布和 API 服务器解耦，NT 容器重启不会影响安装包下载。
- OSS 对大文件、断点续传、并发下载和 CDN 更适合。
- 可以用 RAM 最小权限账号或短期 STS 凭据，只允许写入发布前缀。
- 生命周期和发布脚本可以自动清理旧版本。

建议下载域名单独使用，例如：

```text
https://download.yan.life/rustdesk/stable/<release-id>/<filename>
```

manifest 的 `target.primary` 指向该下载域名。客户端不需要改动，仍然由 API 返回 manifest。

### 4.2 OSS 地区

地区按最终用户分布选择，不按 ACR 镜像仓库地区选择：

- 主要用户和 API 在中国大陆：优先 `cn-shanghai`，其次 `cn-hangzhou`。
- 主要用户在华东且 NT 在上海：优先 `cn-shanghai`，网络路径最简单。
- 主要用户在境外，或不希望下载链路依赖大陆网络：考虑 `cn-hongkong`，但成本和跨境带宽需要单独核算。
- 如果用户分布不确定，先使用一个地区和自定义下载域名，后续再加 CDN，不要同时维护多个源。

推荐默认：`cn-shanghai` OSS bucket + HTTPS 自定义域名；有境外用户时再接 CDN 或增加镜像。

### 4.3.1 已创建的生产候选 Bucket

用户已在阿里云创建以下 Bucket，后续 OSS 发布配置以此为准：

```text
Bucket：rustdesk-release
地域：华东2（上海）
Endpoint：oss-cn-shanghai.aliyuncs.com
Bucket 域名：rustdesk-release.oss-cn-shanghai.aliyuncs.com
存储类型：标准存储
冗余类型：同城冗余存储（控制台当前显示 LRS 起步）
当前读写权限：私有
版本控制：未开启
归档直读：未开启
访问协议：HTTPS 支持
```

当前截图显示文件不能被公共访问，因此在发布 Action 和自定义下载域名正式联调前，需要完成其中一种访问链路：

1. 推荐：保持 Bucket 私有，由 CDN/Oss 访问身份提供下载，并让 `download.yan.life` 指向 CDN CNAME；或
2. 简化测试：将精确发布对象设置为可公开读取，但不开放 Bucket 列表；或
3. 由 API 生成短期签名 URL，manifest 返回签名下载地址。

正式方案默认采用第 1 种。Bucket 不开启公开目录列表，客户端只访问 manifest 返回的精确对象 URL。

证书进度（2026-09-30）：已为 `download.yan.life` 上传 `fullchain.pem` 和 `privkey.pem`，阿里云已显示域名状态“已生效”。`curl -I https://download.yan.life/` 已返回 `Server: AliyunOSS` 与预期的私有 Bucket `403 AccessDenied`，证明 DNS、OSS 自定义域名、HTTPS 和 OSS 路由均已打通。

对象路径验证（2026-09-30）：请求 `https://download.yan.life/rustdesk/test/test.txt` 返回 OSS `AccessDenied`（bucket ACL）。该结果证明对象路径已到达正确 Bucket；由于当前对象未公开读取或尚未上传，不能用它判断文件存在性。正式联调时使用真实发布对象和签名/授权下载 URL 验证。

### 4.3 文件保留策略

建议保留 5 个版本，而不是无限累积：

```text
当前生产版本
前 4 个历史版本（含回滚版本）
```

每个版本使用独立前缀：

```text
rustdesk/stable/yan-v1.5.0-build20260930.2/<filename>
```

OSS 生命周期规则可以清理临时上传文件和未完成分片；“只保留最近 5 个正式版本”由发布 Action 在新版本发布成功后执行：

1. 先完整上传并校验新版本。
2. 先发布 manifest 并完成下载验证。
3. 列出 `stable/` 下的 release 前缀。
4. 按 `build_seq` 排序。
5. 保留最新 5 个前缀。
6. 删除更旧前缀及其中对象。
7. 删除失败时只报警，不删除当前 3 个版本。

不能只依赖对象级生命周期按天数删除，因为发布时间间隔不固定，可能误删仍在使用的回滚版本。

### 4.4 OSS 权限

推荐 bucket 不开放对象列表，只允许精确对象读取；发布账号只允许写入：

```text
rustdesk/stable/*
rustdesk/beta/*
```

安装包本身不是秘密，下载对象可以使用公开读或 CDN 下载鉴权。无论是否公开读，都必须保留 manifest 中的 size、SHA-256 和 Ed25519 校验，不能把对象访问控制当成完整性校验。

GitHub Actions 不使用主账号 AccessKey，优先使用 OIDC/RAM Role；如果当前环境不支持 OIDC，再使用权限受限的 RAM 子账号密钥，并只放在 GitHub Secrets。

## 5. NT 数据目录边界

Termark 资产：

```text
ID: a5f1409a-a338-4af0-8955-64d59b3400f2
生产应用目录: /opt/1panel/apps/rustdesk-api
容器: rustdesk-api
```

持久化目录使用：

```text
/opt/1panel/apps/rustdeskapi-data
```

禁止把整个目录覆盖到容器应用根目录。推荐只挂明确子目录：

```text
/opt/1panel/apps/rustdeskapi-data/data
    -> /var/www/data:rw

/opt/1panel/apps/rustdeskapi-data/releases
    -> /var/www/releases:ro
```

不采用：

```text
/opt/1panel/apps/rustdeskapi-data -> /var/www
/opt/1panel/apps/rustdeskapi-data -> /var/www/html
/opt/1panel/apps/rustdeskapi-data -> /opt/1panel/apps/rustdesk-api
```

数据库仍放在 `/var/www/data/rustdesk.db`，Web 根目录之外。上传流程只写宿主机 `releases`，容器通过只读挂载提供下载。

## 5. Release 产物目录和 URL

建议目录：

```text
/opt/1panel/apps/rustdeskapi-data/releases/
└── stable/
    └── yan-v1.5.0-build20260930.2/
        ├── rustdesk-1.5.0-standard-windows-x86_64.exe
        ├── rustdesk-1.5.0-standard-windows-x86_64.msi
        ├── rustdesk-1.5.0-standard-aarch64.dmg
        ├── rustdesk-1.5.0-standard-android-aarch64.apk
        ├── rustdesk-1.5.0-sos-windows-x86_64.exe
        ├── rustdesk-1.5.0-sos-windows-x86_64.msi
        ├── rustdesk-1.5.0-sos-aarch64.dmg
        └── rustdesk-1.5.0-sos-android-aarch64.apk
```

OSS 公开 URL：

```text
https://download.yan.life/rustdesk/stable/yan-v1.5.0-build20260930.2/<filename>
```

`target.primary` 指向 OSS/CDN 的公开 URL，不直接指向 GitHub Release。NT 的 `/update/files/` 保留为可选兼容路径，不作为新版本的主下载源。

## 6. Manifest 设计

每次发布生成一个 stable manifest，至少包含：

```json
{
  "version": "1.5.0",
  "build_number": "20260930.2",
  "build_seq": 2026093002,
  "product": "rustdesk-yan",
  "edition": "custom",
  "channel": "stable",
  "source_commit": "<release-commit>",
  "targets": {}
}
```

每个 target 至少包含：

```json
{
  "primary": "https://rdapi.yan.life/update/files/.../package.exe",
  "mirrors": [],
  "size": 25794560,
  "sha256": "<sha256>",
  "signature": "<base64-ed25519-signature>",
  "signature_key_id": "yan-release-2026"
}
```

Windows x86_64、macOS aarch64、Android aarch64 的命名必须以已打包客户端实际 target 选择逻辑为准，不能只凭文件名猜测。服务端发布器应同时检查 manifest key、文件名、平台、架构和文件格式。

## 7. 签名与公钥

发布器使用 Ed25519 私钥对完整产物签名。

私钥只存在于 GitHub Actions Secret 或受控发布机，不进入仓库、NT 下载目录或 API 数据库。

API 对外提供：

```text
GET https://rdapi.yan.life/rd/update/v1/keys.json
```

公钥配置格式：

```json
{
  "schema": 1,
  "keys": [
    {
      "id": "yan-release-2026",
      "type": "ed25519",
      "public_key": "<base64-public-key>"
    }
  ]
}
```

发布前必须验证：

```text
manifest.signature_key_id == keys.json 中的 key.id
签名可以由对应公钥验证
下载文件 sha256 与 manifest 一致
```

## 8. 自动化发布流程

### GitHub Actions

Release workflow 在构建和 GitHub Release 成功后增加发布 job：

1. 下载本次 Release assets。
2. 只选择批准的 Windows/macOS/Android 产物。
3. 计算 size 和 SHA-256。
4. 使用 GitHub Secret 中的 Ed25519 私钥签名。
5. 通过 OSS OIDC/RAM Role 或受限 STS 凭据上传 OSS release 前缀。
6. 调用 API 的机器发布接口提交 manifest。
7. 读取公开 manifest、keys.json 和每个下载 URL 进行发布后校验。
8. 发布成功后清理旧于最近 5 个版本的 OSS release 前缀。

### API 机器发布接口

现有管理员接口依赖浏览器会话和 CSRF，不适合直接给 GitHub Actions 使用。建议增加受保护的机器发布接口：

```text
POST /rd/update/v1/publish
Authorization: Bearer <release-publish-token>
```

服务端原子执行：

1. 校验版本、build_seq、channel。
2. 校验 manifest schema 和所有 target。
3. 校验 URL 必须在配置的公开下载前缀下。
4. 校验 size、sha256、signature_key_id。
5. 写入 `update_releases`。
6. 写入发布审计事件。
7. 保留旧版本，不覆盖历史 manifest。

发布 token 只放 GitHub Secret；不放仓库、不放客户端、不放公开 URL。

## 9. 服务端需要的改动

### 必须改

- 恢复 Nginx `/update` 和 `/update/` 精确 404。
- 生产 Compose 只增加 `data` 子目录挂载；如果保留 NT 兼容下载，再增加只读 `releases` 子目录挂载。
- 建立 `RUSTDESK_UPDATE_BASE_URL`、`RUSTDESK_UPDATE_KEYS_JSON` 和 releases 路径的生产配置。
- 增加 manifest 严格校验。
- 增加机器发布接口或等价受保护发布命令。
- 发布 stable manifest，主 URL 指向 OSS/CDN。
- 增加发布后真实下载和 hash 校验。

### 建议改

`device_update_events` 增加并保存：

```text
product
edition
build_number
channel
source_commit
```

保留未知扩展字段，避免客户端新增遥测后服务端静默丢失。

## 10. 测试门禁

### API

- SQLite 真实 CRUD、manifest 发布、check、events、直接 SQL 校验。
- MySQL 8.4 真实容器跑同一套发布/check/events 流程。
- 校验旧客户端版本返回 `update_available=true`。
- 校验同版本低 build_seq 可以升级，高 build_seq 不重复升级。
- 校验 product、edition、platform、arch 不匹配时不下发。
- 校验错误签名、错误 hash、错误 size 被拒绝。
- 校验重复发布幂等、旧版本可回滚。

### Nginx/容器

- `/update` 和 `/update/` 返回 404。
- `/update/files/` 不返回目录列表。
- OSS 精确对象 URL 返回正确文件；如果启用 NT 兼容路径，NT 精确文件 URL 也返回正确文件。
- 容器重启后数据库和 releases 文件仍存在。
- releases 容器内只读，API 不能通过 Web 路由写入。

### 客户端联调

- 现有旧客户端检查到 `.2` 目标。
- Windows EXE/MSI 分流正确。
- macOS ARM 下载和签名校验正确。
- Android 下载后进入系统安装确认流程。
- 事件 `started/downloaded/installed/failed/rolled_back` 全部落库。
- 管理端详情显示最后检查时间、状态、来源、目标版本和错误码。

## 11. 回滚方案

- 新版本目录按 release id 隔离，不覆盖旧文件。
- manifest 发布记录保留历史版本。
- 关闭某个 release 的 `active` 状态即可停止下发。
- Compose 配置变更前备份原文件和数据库。
- releases 挂载验证失败时先恢复原 Compose，不删除原 `data`。
- 发布器失败时不写入 manifest，避免客户端拿到半成品。

## 12. 需要用户提供或确认的事项

### 已完成，不需要重复提供

```text
Bucket：rustdesk-release
地域：华东2（上海）
Endpoint：oss-cn-shanghai.aliyuncs.com
下载域名：download.yan.life
CNAME：download -> rustdesk-release.oss-cn-shanghai.aliyuncs.com
HTTPS：fullchain.pem + privkey.pem，OSS 域名状态已生效
```

### 现在只剩 4 项

1. **GitHub Actions 上传权限**：创建一个只允许写入 `rustdesk-release/rustdesk/{stable,beta}/*` 的 RAM OIDC Role，并提供 Role ARN。不要把 AccessKey 或私钥发到聊天；如果暂时不能用 OIDC，再创建权限受限的 RAM 子账号并放入 GitHub Secrets。
2. **Bucket 下载策略**：推荐先保持 Bucket 私有，使用 CDN/签名下载；如果先做最短闭环测试，则确认“安装包对象允许公共读、Bucket 禁止列目录”。
3. **Ed25519 发布签名**：确认使用现有密钥，或允许生成新的 `yan-release-2026`。私钥只进 GitHub Secret，公钥进入 API 配置。
4. **发布范围**：确认自动上传 standard + SOS 全部 8 个资产，并保留最近 5 个版本。

另外需要确认是否启用 API 机器发布 token；默认启用，token 只放 GitHub Secret 和生产环境变量。

2026-09-30 凭据记录：OSS 发布用 Secrets 已改为配置在客户端仓库 `jandoucn/rustdesk`，名称为 `ALIYUN_ACCESS_KEY_ID` 和 `ALIYUN_ACCESS_KEY_SECRET`。API 仓库的 ACR 凭据与 OSS 发布凭据分离。

客户端连接行为变更（待客户端仓库发布）：Windows/Android 控制 macOS 时自动开启 Control/Command 交换；macOS 控制 macOS 不自动开启。每次普通连接会将持久化的浏览模式复位为关闭，显式从“浏览模式”入口连接时才开启，避免关闭后下一次双击仍停留在浏览模式。
