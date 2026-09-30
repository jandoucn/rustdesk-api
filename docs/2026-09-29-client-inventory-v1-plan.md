# 客户端管理与通讯录管理第一版落地方案

> 状态：第一版已实现并通过双数据库回归与独立代码审查
>
> 适用仓库：`/Users/olly/github/rustdesk-api`
>
> 客户端事实来源：`/Users/olly/github/rustdesk`

## 1. 目标与边界

### 1.1 第一版目标

第一版只围绕两个后台页面落地：

1. 客户端管理：稳定、紧凑、可扫描，自动刷新不跳行。
2. 通讯录管理：个人地址簿、别名、标签、收藏和客户端同步关系清晰。

页面需要支持以下信息，但不把所有字段同时铺在表格中：

- RustDesk 客户端版本，例如 `1.5.0`。
- 平台，例如 Windows、macOS、Linux、Android、iOS。
- 发行形态，例如安装版、SOS、便携版、AppImage、移动端。
- 设备 ID、UUID、主机名、系统用户。
- 操作系统、CPU、内存。
- 公网 IP、内网 IP、地区；地区由服务端 GeoLite 查询。
- 在线状态、最后心跳、当前连接数、部署状态。
- 通讯录别名、标签、分组、收藏。

### 1.2 明确不在第一版的内容

- 不把连接审计、文件审计、录屏文件做成完整业务模块。
- 不把所有 RustDesk 客户端管理命令一次性做完。
- 不在服务端凭空猜测 MSI、SOS 或便携版。
- 不让 API 运行时依赖 `/root/next-terminal` 的目录结构。
- 不用 heartbeat 时间作为动态列表排序键。

## 2. 事实来源和审查结论

### 2.1 唯一客户端事实来源

以后涉及 heartbeat、sysinfo、通讯录同步、设备身份、部署、客户端命令或版本识别，必须先审查：

```text
/Users/olly/github/rustdesk
```

不得用 upstream RustDesk、公开文档或未修改客户端推测字段。跨仓库变更必须同时验证两个仓库，并在提交中记录有意差异。

### 2.2 当前客户端真实调用面

| 路由 | 方法 | 用途 | 当前 API 状态 |
|---|---|---|---|
| `/api/heartbeat` | POST | 在线状态、连接列表、策略版本 | 已覆盖 |
| `/api/sysinfo` | POST | 版本、系统、主机和用户信息 | 已覆盖 |
| `/api/sysinfo_ver` | GET/POST | sysinfo 内容版本检查 | 已覆盖 |
| `/api/login-options` | GET | 登录能力/HTTP 预热 | 已覆盖 |
| `/api/oidc/auth` | POST | OIDC 登录初始化 | 路由存在但当前 404 |
| `/api/oidc/auth-query` | GET | OIDC 登录轮询 | 路由存在但当前 404 |
| `/api/devices/deploy` | POST | 下发设备 ID、UUID、公钥 | 已覆盖 |
| `/api/devices/cli` | POST | 修改设备和通讯录预置字段 | 已覆盖 |
| `/api/audit/conn` | POST | 连接审计 | 已覆盖，查询侧待补 |
| `/api/audit/file` | POST | 文件审计 | 已覆盖，查询侧待补 |
| `/api/audit/alarm` | POST | 安全告警 | 已覆盖，查询侧待补 |
| `/api/record` | POST | 录屏分片上传 | 已覆盖，生命周期待补 |
| `/api/switch-grant` | POST | 切换授权 | 已覆盖，但服务端当前未验签 |
| `/api/ab*` | 多种 | 地址簿和 peer 管理 | 已覆盖，需继续验证跨表一致性 |

结论：当前没有发现会阻断客户端心跳、sysinfo、部署和通讯录同步的明显缺路由；主要问题是数据完整性、安全校验、查询能力和页面表现，而不是简单地再加一批 URL。

### 2.3 当前客户端上报字段

#### heartbeat

```json
{
  "id": "设备 ID",
  "uuid": "Base64 UUID",
  "ver": 版本号数字,
  "conns": [],
  "modified_at": 0
}
```

heartbeat 当前没有内网 IP、发行形态或安装来源字段。

#### sysinfo

```json
{
  "version": "1.5.0",
  "id": "设备 ID",
  "uuid": "Base64 UUID",
  "cpu": "CPU 名称、频率、核心数",
  "memory": "内存容量",
  "os": "操作系统与版本",
  "hostname": "主机名",
  "username": "系统用户"
}
```

Android/iOS 通常没有桌面端 username。当前也没有网卡、公网 IP、地区、ASN、磁盘或 GPU 字段。

## 3. 版本和发行形态模型

### 3.1 版本字段不能混用

服务端同时保存两个值：

- `version_text`：来自 sysinfo 的字符串，例如 `1.5.0`，用于显示。
- `heartbeat_version`：来自 heartbeat 的数值版本，用于客户端协议兼容性判断。

不能把 `heartbeat.ver` 直接格式化成用户看到的版本号，也不能用页面显示版本覆盖协议版本。

### 3.2 发行形态的事实等级

发行形态分为三种事实等级，页面必须标明未知而不是伪造精确结果：

#### A. 客户端明确上报，可信度最高

建议在本地 RustDesk 客户端 sysinfo 增加明确字段：

```json
{
  "platform": "windows",
  "distribution": "sos",
  "install_mode": "portable",
  "client_arch": "x64",
  "executable_name": "RustDesk.exe"
}
```

推荐枚举：

```text
platform:
  windows | macos | linux | android | ios | unknown

distribution:
  desktop | mobile | sos | installed | portable | appimage |
  linux_package | unknown

install_mode:
  installed | portable | live | unknown
```

`distribution` 和 `install_mode` 是两个维度：MSI 安装出来的是 `distribution=installed`、`install_mode=installed`；SOS 是 `distribution=sos`，通常 `install_mode=portable`；单文件便携版是 `distribution=portable`、`install_mode=portable`。

#### B. 客户端源码可可靠推导，可信度中等

本地 RustDesk 已存在可利用的事实线索：

- Android/iOS 与桌面端由编译条件区分。
- `RUSTDESK_SOS=1` 可识别 SOS 构建。
- `is_installed` 可识别当前运行实例是否安装。
- `current_exe()` 和可执行文件名可辅助识别便携运行。
- `--install`、`--noinstall` 表示安装/不安装路径。

这些字段可以先在客户端内部统一成 `distribution`，再随 sysinfo 上报。

#### C. 服务端只能推断，可信度最低

服务端可以根据 `platform`、`executable_name`、`is_installed` 做兼容推断，但不能保证区分“MSI 安装版”和“手工复制到 Program Files 的 EXE”。页面应显示：

```text
安装版（客户端声明）
安装版（服务端推断）
未知发行形态
```

禁止仅凭文件名把所有 `RustDesk.exe` 标成 MSI，也禁止仅凭 Windows 就标成桌面安装版。

### 3.3 第一版推荐展示

主表只显示一行摘要：

```text
Windows · 安装版 · 1.5.0
Android · 移动端 · 1.5.0
Windows · SOS · 1.5.0
```

详情中显示完整字段：平台、版本、发行形态、安装模式、架构、可执行文件名、数据来源和可信度。

### 3.4 对现有老客户端的兼容

老客户端没有新字段时：

- `platform` 从现有 `os` 文本做有限归一化。
- `version_text` 继续使用现有 sysinfo version。
- `distribution=unknown`。
- `install_mode=unknown`。
- 页面显示“未声明发行形态”，不阻断心跳和通讯录。

新字段必须是可选字段，服务端不能因旧客户端缺少字段而拒收 sysinfo。

## 4. IP、地区和网络信息设计

### 4.1 公网 IP

服务端在接收 heartbeat/sysinfo 时记录请求源地址，保存为 `last_public_ip` 和 `public_ip_seen_at`。

反代环境下只信任明确配置的本机 OpenResty 代理传递的 `X-Real-IP`/`X-Forwarded-For`，不能无条件信任客户端自带 Header。应增加代理信任配置，而不是在 PHP 中直接取任意转发头。

### 4.2 内网 IP

当前 RustDesk 客户端不会上报内网 IP。内网 IP 必须由 `/Users/olly/github/rustdesk` 增加可选 sysinfo 字段，例如：

```json
{
  "network": {
    "private_ips": ["192.168.1.20", "10.0.0.8"]
  }
}
```

只上报非环回、非链路本地地址；默认不上传网卡 MAC。服务端按地址数组保存，不能把多个 IP 拼成不可查询的展示字符串。

### 4.3 地区和 GeoLite

地区只对公网 IP 做 GeoLite2-City 查询。建议将数据库复制到 API 自己的数据目录，例如：

```text
data/GeoLite2-City.mmdb
```

镜像携带一份只读种子，容器首次启动时自动复制到持久卷的 `/var/www/data/GeoLite2-City.mmdb`；镜像升级不覆盖数据卷中已有版本。生产环境不依赖 `/root/next-terminal/data/GeoLite2-City.mmdb`。保存：

- country_code
- country_name
- region_name
- city_name
- latitude/longitude（默认不在主表显示）
- geo_source
- geo_updated_at

内网 IP 不做地理定位。地区查询失败不影响设备上报。

## 5. 数据模型第一版

### 5.1 设备报告保留原文

现有 `device_reports.payload` 和 `heartbeat_payload` 继续保留，作为审计和兼容依据，不删除未知 JSON 字段。

### 5.2 建议的结构化字段

可在现有设备报告或独立扩展表中增加：

```text
device_network:
  device_key (id + uuid)
  last_public_ip
  last_public_ip_seen_at
  private_ips_json
  geo_country_code
  geo_region
  geo_city
  geo_source
  geo_updated_at

device_runtime:
  device_key (id + uuid)
  platform
  version_text
  heartbeat_version
  distribution
  install_mode
  client_arch
  executable_name
  detection_source
  detection_confidence
  updated_at
```

如果 SQLite/MySQL 两套实现都能安全迁移，也可以直接给 `device_reports` 增加列；但不要把结构化字段塞回 JSON 后再用字符串搜索。最终选择由实施阶段根据现有迁移工具决定。

### 5.3 设备主键

展示和更新使用 `id + uuid` 作为设备行键。单独使用 ID 不够：设备 ID 可能被重新部署或修改，UUID 用来区分同名/旧记录。

## 6. 客户端管理页面第一版

### 6.1 稳定主表

桌面端推荐 6 个视觉单元：

1. **设备**：别名或主机名、ID、状态。
2. **版本**：平台、发行形态、版本号。
3. **网络**：公网 IP 摘要；无公网 IP 时显示“未知”。
4. **连接**：当前连接数和最近活动。
5. **地址簿**：标签、分组、收藏。
6. **操作**：详情、编辑别名、通讯录操作。

系统信息（CPU、内存、完整 OS、用户、hostname）不单独占列，归入“系统”详情。

### 6.2 详情交互

- 桌面端：版本、网络、系统单元支持 hover 卡片。
- 所有平台：点击同一单元打开详情抽屉。
- 移动端：不能依赖 hover，点击行或“详情”按钮打开抽屉。
- 抽屉中分组显示“身份、版本、系统、网络、通讯录、时间线、原始上报”。
- 原始 JSON 默认折叠，仅用于排查。

### 6.3 自动刷新不跳动

后端：

- heartbeat 只更新状态、最后心跳、连接数，不更新列表排序键。
- 默认排序固定为：收藏/分组、别名/主机名、设备 ID；同值按 `id + uuid`。
- 新设备插入明确位置，不因心跳时间变化而移动。

前端：

- 使用 `id + uuid` 做行键。
- 增量更新单元格，不再每次 `replaceChildren()` 重建整表。
- 刷新时保存滚动位置、详情抽屉、编辑状态和焦点。
- 文档隐藏时暂停刷新，重新可见后再刷新一次。
- 刷新失败保留旧数据并显示非阻塞错误状态。

### 6.4 字体和密度

普通 UI 使用系统中文字体：

```css
-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
"Microsoft YaHei", sans-serif
```

仅设备 ID、UUID、IP 使用等宽字体。统计数字、时间和中文说明不再全部使用 monospace。控件保持 40px 高度、清晰焦点态和无横向溢出。

## 7. 通讯录页面第一版

### 7.1 数据边界

通讯录永远是当前登录管理员的个人地址簿，不返回其他用户的数据。

创建、更新、删除 peer 时，在同一事务内同步：

- `ab_profile_peers`
- `rustdesk_peers`
- `address_books.payload`

保留未知 JSON 字段；删除通讯录 peer 不删除 heartbeat/sysinfo/deployment 记录。

### 7.2 页面默认显示

主表显示：

- 别名
- RustDesk ID
- 标签/分组
- 在线摘要
- 版本摘要
- 收藏
- 最近活动

完整系统和网络信息沿用客户端详情抽屉，不在通讯录表中重复铺开。

### 7.3 交互要求

- 别名为空时显示为空或“未标注”，不拿 hostname 冒充别名。
- 修改别名后明确提示：客户端将在下一次地址簿同步时收到别名。
- 标签重命名/删除按精确 tag 值处理，不能改写相似字符串。
- 收藏是管理员私有元数据，不写入 RustDesk 客户端原生 favorites。
- 删除和批量操作必须确认，并显示成功/失败反馈。

## 8. 接口和安全缺口优先级

### P0：第一版必须处理

1. 修复稳定排序和增量刷新。
2. 明确保存 `id + uuid`，避免设备 ID 变化导致串设备。
3. heartbeat/sysinfo 兼容未知字段并保存原文。
4. 为新版本/发行形态字段增加 SQLite 和 MySQL 等价覆盖。
5. 公网 IP 代理信任边界配置化。

### P1：第一版后半段处理

1. `/api/switch-grant` 使用部署保存的公钥验证客户端 Ed25519 签名。
2. heartbeat/sysinfo 防止任意来源覆盖同一设备报告，至少记录冲突来源并按设备公钥/UUID约束。
3. audit 接口增加设备绑定、查询、保留和清理策略。
4. record 接口增加设备绑定、分片清理和生命周期状态。

### P2：后续模块

1. OIDC 完整实现。
2. 连接审计、文件审计、告警查询页面。
3. 录屏管理和存储策略。
4. 更细粒度的客户端远程命令和策略管理。

## 9. 实施分阶段计划

### 阶段 A：服务端兼容层

- 增加结构化版本/平台/发行形态字段，老客户端默认 unknown。
- 记录请求源公网 IP。
- 接入 GeoLite2，只读、可选、失败不阻断上报。
- 增加迁移、回滚和 SQLite/MySQL 对等测试。

### 阶段 B：本地 RustDesk 客户端上报增强

- 在本地客户端统一生成平台、发行形态、安装模式、架构、可执行文件名。
- SOS 使用构建标志明确标记。
- Android/iOS 明确标记 mobile，不从文本猜。
- Windows 安装版、便携版、单文件运行版使用 `is_installed` 与运行模式；无法区分 MSI 时上报 `installed` 而不是伪造 `msi`。
- 可选增加私网 IP 数组，默认不上传 MAC。
- 用旧客户端和新客户端分别跑 heartbeat/sysinfo 集成测试。

### 阶段 C：客户端管理页面

- 稳定排序、行键和增量刷新。
- 字体层级、紧凑表格、详情抽屉、hover 卡片。
- 桌面和 390px 移动端 Playwright 覆盖。

### 阶段 D：通讯录页面

- 事务一致性、个人范围隔离、标签/收藏/别名操作。
- 超过 200 条分页和最终页测试。
- 桌面和 390px 移动端完整工作流测试。

### 阶段 E：安全和运维收口

- switch-grant 验签。
- 上报冲突检测和审计日志。
- GeoLite 数据库版本、大小、SHA-256 记录。
- 容器挂载、重启持久化和回滚验证。

## 10. 必须通过的验证门禁

每个行为变更都必须同时覆盖：

1. SQLite：基于 disposable legacy database 的 HTTP 集成测试。
2. MySQL 8.4：全新真实容器集成测试。
3. 直接 SQL 断言：创建、更新、删除值，JSON、BLOB 大小、时间戳和行数。
4. Playwright：桌面和 390px 页面、正常/错误状态、自动刷新、详情抽屉和破坏性确认。
5. 容器：内部端口 80、Compose 7000 映射、管理路径、公开 fallback、卷重启持久化。
6. 静态检查：PHP syntax、Python compile、Compose config、`git diff --check`。
7. RustDesk 客户端改动：在 `/Users/olly/github/rustdesk` 做对应编译和 sysinfo/heartbeat 兼容测试。

## 11. 第一版验收标准

确认下列结果全部成立才算第一版完成：

- 旧客户端仍能正常 heartbeat、sysinfo、登录和通讯录同步。
- 新客户端能显示正确平台、版本和发行形态；无法确认时明确显示未知。
- Windows SOS、安装版、便携版、单文件运行版不被错误合并成同一种标签。
- MSI 只有在客户端明确声明或构建元数据明确时才显示 MSI；否则显示安装版/未知。
- Android/iOS 显示移动端和实际版本。
- 自动刷新 5 秒执行时，行顺序、滚动位置、详情抽屉和编辑状态不跳动。
- 320、390、768、1024、1440 宽度无横向溢出。
- CPU、内存、OS、IP、地区等完整信息在详情抽屉可见，但主表保持紧凑。
- 通讯录数据只属于当前管理员，别名/标签/收藏/原始 payload 保持事务一致。
- SQLite 和 MySQL 行为一致，容器重启后数据和 GeoLite 挂载不丢失。

## 12. 待确认的产品决策

实施前只需要确认以下三点：

1. 主表网络摘要是否优先显示公网 IP；没有公网 IP 时是否显示内网 IP。
2. 发行形态显示是否接受“安装版”作为 MSI 的上位分类，只有客户端明确上报时才细分为 MSI。
3. 第一阶段是否同步修改 `/Users/olly/github/rustdesk` 上报字段，还是先做服务端兼容字段和页面，客户端字段随后补齐。

默认建议：公网 IP 优先；MSI 不做猜测；第一阶段先完成服务端兼容层和页面稳定性，再同步修改本地 RustDesk 客户端上报字段。

## 13. 第一版完成状态

本轮已在本仓库完成的可验证部分：

- 管理端列表改为稳定排序，排序不再使用 heartbeat 时间。
- 前端复用 `id + uuid` 行节点，增加版本/发行形态、网络摘要和详情抽屉。
- 详情抽屉聚合身份、系统、版本、网络和状态信息。
- 服务端保存可选 runtime/network 上报扩展，并兼容旧客户端缺字段的 sysinfo。
- 私网地址过滤、去重和数量上限已加入。
- 公网 IP 支持配置可信代理 IP 后读取 `X-Real-IP`/`X-Forwarded-For`。
- SQLite/MySQL schema 版本升级为 v8，`device_deployments` 使用大小写敏感的 `(id, uuid)` 复合主键，并可从旧数据库及早期 v7 迁移。
- TDD、真实数据、直接 SQL、Playwright、浏览器/MCP 和代码审查要求已写入 `AGENTS.md`。
- `/Users/olly/github/rustdesk` 已通过提交 `ec1383758`、`8a196cc63`、`98d04e6c1` 上报平台、发行形态、运行模式、架构、可执行文件和内网 IP；发布构建由独立线程持续验证。
- 容器已安装 `maxminddb` 扩展，可从 `RUSTDESK_GEOIP_DATABASE` 指定的只读 MMDB 查询国家、地区、城市、时区和坐标；无数据库或查询失败时不阻断心跳。
- 列表合并、部署写入、别名目标校验和删除均按 `(id, uuid)` 定位；同 ID 多 UUID 时，未指定 UUID 的兼容调用返回冲突，不会批量误删。
- 未变化设备不重建 DOM 行；自动刷新保留行内键盘焦点和页面滚动位置。
- SQLite/辅助测试共 33 项通过（GeoLite 专项在无扩展的宿主 PHP 环境条件跳过），GeoLite 容器实库验证了 London 解析及公网 IP 变化时旧地区原子清除；MySQL 8.4 为 18/18，包含真实 v5/v6 迁移；Playwright Chromium 在 SQLite 和 MySQL 上分别为 14/14，并覆盖 320、390、768、1024、1440 宽度及长列表刷新锚点。
- 独立代码审查发现的 MySQL 大小写身份、嵌套 JSON 保留、IP/Geo 一致性、错误 UUID 假成功、旧 MySQL 迁移覆盖和长列表 E2E 六项问题均已修复并加入回归测试。
