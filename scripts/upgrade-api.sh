#!/usr/bin/env bash
set -Eeuo pipefail

REGISTRY_IMAGE="${RUSTDESK_API_IMAGE_REPOSITORY:-crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api}"
COMPOSE_SERVICE="${RUSTDESK_COMPOSE_SERVICE:-api}"
HEALTH_TIMEOUT="${RUSTDESK_UPGRADE_HEALTH_TIMEOUT:-60}"
TARGET_VERSION="${1:-latest}"
BACKUP_NAME=""

log() { printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
die() { printf '\n升级失败: %s\n' "$*" >&2; exit 1; }

case "$TARGET_VERSION" in
  v*) TARGET_TAG="${TARGET_VERSION#v}" ;;
  *) TARGET_TAG="$TARGET_VERSION" ;;
esac
[[ "$TARGET_TAG" =~ ^(latest|[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?)$ ]] || die "版本格式无效: $TARGET_VERSION"
[[ "$HEALTH_TIMEOUT" =~ ^[1-9][0-9]*$ ]] || die "RUSTDESK_UPGRADE_HEALTH_TIMEOUT 必须是正整数"

command -v docker >/dev/null 2>&1 || die "未安装 docker"
docker info >/dev/null 2>&1 || die "Docker daemon 未运行"
docker compose version >/dev/null 2>&1 || die "缺少 docker compose 插件"
[[ -f docker-compose.yaml || -f compose.yaml || -f compose.yml ]] || die "请在 RustDesk API Compose 目录执行"
docker compose config --quiet

TARGET_IMAGE="$REGISTRY_IMAGE:$TARGET_TAG"
CURRENT_CONTAINER="$(docker compose ps -q "$COMPOSE_SERVICE")"
[[ -n "$CURRENT_CONTAINER" ]] || die "服务 $COMPOSE_SERVICE 尚未创建"
CURRENT_IMAGE_ID="$(docker inspect "$CURRENT_CONTAINER" --format '{{.Image}}')"
CURRENT_IMAGE_REF="$(docker inspect "$CURRENT_CONTAINER" --format '{{.Config.Image}}')"
ROLLBACK_TAG="rustdesk-api-upgrade-rollback:$(date '+%Y%m%d%H%M%S')"
docker image tag "$CURRENT_IMAGE_ID" "$ROLLBACK_TAG"

backup_sqlite() {
  local driver
  driver="$(docker inspect "$CURRENT_CONTAINER" --format '{{range .Config.Env}}{{println .}}{{end}}' | sed -n 's/^RUSTDESK_DB_DRIVER=//p' | head -n1)"
  [[ "${driver:-sqlite}" == "sqlite" ]] || return 0
  BACKUP_NAME="rustdesk.pre-upgrade-${TARGET_TAG}-$(date '+%Y%m%d%H%M%S').db"
  log "创建 SQLite 在线备份: data/$BACKUP_NAME"
  docker compose exec -T -e BACKUP_NAME="$BACKUP_NAME" "$COMPOSE_SERVICE" php -r '
    $source = getenv("RUSTDESK_DB") ?: "/var/www/data/rustdesk.db";
    $target = dirname($source)."/".getenv("BACKUP_NAME");
    if (!is_file($source)) { fwrite(STDERR, "database not found: $source\n"); exit(2); }
    if (is_file($target)) { fwrite(STDERR, "backup already exists: $target\n"); exit(3); }
    $db = new PDO("sqlite:".$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("VACUUM INTO ".$db->quote($target));
    $copy = new PDO("sqlite:".$target);
    if ($copy->query("PRAGMA integrity_check")->fetchColumn() !== "ok") { exit(4); }
    chmod($target, 0600);
  '
}

health_path() {
  docker compose exec -T "$COMPOSE_SERVICE" php -r '
    $file = getenv("RUSTDESK_INSTALL_CONFIG") ?: "/var/www/data/install.json";
    $config = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $path = is_array($config) ? (string)($config["admin_path"] ?? "/") : "/";
    echo "/".trim($path, "/").(trim($path, "/") === "" ? "" : "/");
  '
}

wait_healthy() {
  local deadline path status
  deadline=$((SECONDS + HEALTH_TIMEOUT))
  while (( SECONDS < deadline )); do
    if docker compose ps --status running -q "$COMPOSE_SERVICE" | grep -q .; then
      path="$(health_path 2>/dev/null || printf '/')"
      status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:${RUSTDESK_PORT:-7000}${path}" 2>/dev/null || true)"
      if [[ "$status" =~ ^[23][0-9][0-9]$ ]]; then
        return 0
      fi
    fi
    sleep 2
  done
  return 1
}

rollback() {
  log "健康检查失败，回滚到 $CURRENT_IMAGE_REF ($CURRENT_IMAGE_ID)"
  RUSTDESK_API_IMAGE="$ROLLBACK_TAG" docker compose up -d --force-recreate "$COMPOSE_SERVICE"
  if [[ -n "$BACKUP_NAME" ]]; then
    log "恢复 SQLite 升级前快照: data/$BACKUP_NAME"
    if ! docker compose exec -T -e BACKUP_NAME="$BACKUP_NAME" "$COMPOSE_SERVICE" sh -c '
      set -eu
      db="${RUSTDESK_DB:-/var/www/data/rustdesk.db}"
      backup="$(dirname "$db")/$BACKUP_NAME"
      test -s "$backup"
      cp "$backup" "$db.rollback.tmp"
      chmod 0600 "$db.rollback.tmp"
      mv -f "$db.rollback.tmp" "$db"
    '; then
      docker compose logs --no-color --tail=100 "$COMPOSE_SERVICE" >&2 || true
      die "旧镜像已启动，但数据库快照恢复失败；请保留 data/$BACKUP_NAME 手工处理"
    fi
  fi
  docker compose logs --no-color --tail=100 "$COMPOSE_SERVICE" >&2 || true
  die "已恢复旧镜像；SQLite 升级前备份保留在 data/"
}

backup_sqlite
log "拉取镜像 $TARGET_IMAGE"
RUSTDESK_API_IMAGE="$TARGET_IMAGE" docker compose pull "$COMPOSE_SERVICE"
log "重建服务 $COMPOSE_SERVICE"
RUSTDESK_API_IMAGE="$TARGET_IMAGE" docker compose up -d --force-recreate "$COMPOSE_SERVICE"

log "等待服务健康，超时 ${HEALTH_TIMEOUT}s"
wait_healthy || rollback

if docker compose logs --no-color --since=2m "$COMPOSE_SERVICE" | grep -Eiq 'RustDesk API failure|PDOException|SQLSTATE|fatal error'; then
  log "检测到启动/迁移错误日志"
  rollback
fi

NEW_CONTAINER="$(docker compose ps -q "$COMPOSE_SERVICE")"
NEW_IMAGE_ID="$(docker inspect "$NEW_CONTAINER" --format '{{.Image}}')"
log "升级成功"
printf '版本: %s\n镜像: %s\n容器: %s\n状态:\n' "$TARGET_TAG" "$NEW_IMAGE_ID" "$NEW_CONTAINER"
docker compose ps "$COMPOSE_SERVICE"
docker image rm "$ROLLBACK_TAG" >/dev/null 2>&1 || true
