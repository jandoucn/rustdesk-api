# 在线更新密钥与环境变量

本文只记录配置位置、变量名称和用途，不记录任何密钥值。

## GitHub 仓库配置

路径统一为：`Settings -> Secrets and variables -> Actions -> Repository secrets`。

### `jandoucn/rustdesk`

| Secret | 用途 |
| --- | --- |
| `ALIYUN_ACCESS_KEY_ID` | OSS 发布脚本访问阿里云 OSS 的 RAM AccessKey ID。 |
| `ALIYUN_ACCESS_KEY_SECRET` | 与上面的 AccessKey ID 配套，用于 OSS 上传、读取校验和旧版本清理。 |
| `UPDATE_SIGNING_KEY` | Ed25519 私钥种子的 Base64 表示。发布脚本使用它签名安装包；不得部署到 API 服务器或提交到仓库。 |
| `UPDATE_PUBLISH_TOKEN` | 调用 `/rd/update/v1/publish` 的 Bearer token。必须与 API 生产环境使用同一个值。 |

### `jandoucn/rustdesk-api`

| Secret | 用途 |
| --- | --- |
| `UPDATE_PUBLISH_TOKEN` | API 部署使用的发布鉴权 token。值必须与 `jandoucn/rustdesk` 的同名 Secret 一致。 |

GitHub Secret 只能确认名称和更新时间，不能读取或比较原值。需要轮换 `UPDATE_PUBLISH_TOKEN` 时，应一次性覆盖两个仓库并同步生产服务器。

## API 生产服务器

部署目录：`/opt/1panel/apps/rustdesk-api`

配置文件：`/opt/1panel/apps/rustdesk-api/.env`

| 环境变量 | 用途 |
| --- | --- |
| `RUSTDESK_UPDATE_PUBLISH_TOKEN` | API 校验 `/rd/update/v1/publish` Bearer token。值必须与两个 GitHub 仓库中的 `UPDATE_PUBLISH_TOKEN` 一致。 |
| `RUSTDESK_UPDATE_KEYS_JSON` | 客户端更新签名公钥集合。包含 key ID、签名类型和公钥，不包含私钥。 |
| `RUSTDESK_UPDATE_BASE_URL` | 更新 API 对外基础地址。 |
| `RUSTDESK_UPDATE_DOWNLOAD_PREFIX` | 允许写入 manifest 的 OSS 下载地址前缀。 |

Compose 文件：`/opt/1panel/apps/rustdesk-api/docker-compose.yaml`

Compose 必须把以上 `RUSTDESK_UPDATE_*` 变量从 `.env` 映射进 `api` 服务。只修改 `.env` 而不在 Compose 的 `environment` 中声明，容器不会取得这些值。

## Key ID 与公私钥关系

`yan-release-2026` 是签名密钥标识，不是 Secret 值。

- 私钥只存在于 `jandoucn/rustdesk` 的 `UPDATE_SIGNING_KEY`。
- 对应公钥存在于客户端内嵌配置和服务器 `RUSTDESK_UPDATE_KEYS_JSON`。
- manifest 的 `signature_key_id` 使用 `yan-release-2026`，用于选择对应公钥。

轮换 `UPDATE_SIGNING_KEY` 时，必须同时更新客户端内嵌公钥、API 公钥集合和 key ID，并发布包含新公钥的客户端。仅替换 GitHub Secret 会导致更新签名校验失败。

## 配置检查

检查 GitHub Secret 名称：

```bash
gh secret list --repo jandoucn/rustdesk
gh secret list --repo jandoucn/rustdesk-api
```

检查生产容器是否取得配置时，只输出长度或布尔状态，禁止打印实际值：

```bash
docker exec rustdesk-api php -r '
$token = getenv("RUSTDESK_UPDATE_PUBLISH_TOKEN");
$keys = getenv("RUSTDESK_UPDATE_KEYS_JSON");
echo "token_configured=" . ($token !== "" ? "yes" : "no") . PHP_EOL;
echo "keys_valid=" . (json_decode($keys, true) !== null ? "yes" : "no") . PHP_EOL;
'
```

修改 `.env` 或 Compose 后必须重建容器：

```bash
cd /opt/1panel/apps/rustdesk-api
docker compose config
docker compose up -d --force-recreate api
docker compose ps api
```
