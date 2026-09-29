#!/usr/bin/env bash
set -Eeuo pipefail

API_IMAGE="${RUSTDESK_API_IMAGE:-ghcr.io/jandoucn/rustdesk-api:latest}"
PROVISIONER_IMAGE="${RUSTDESK_PROVISIONER_IMAGE:-ghcr.io/jandoucn/rustdesk-api-provisioner:latest}"
MYSQL_IMAGE="${MYSQL_IMAGE:-docker.1ms.run/mysql:8.4}"
CONTAINER_NAME="${RUSTDESK_CONTAINER_NAME:-rustdesk-api}"
PROVISIONER_NAME="${RUSTDESK_PROVISIONER_NAME:-rustdesk-api-provisioner}"
NETWORK_NAME="${RUSTDESK_NETWORK_NAME:-rustdesk-api-net}"
DATA_VOLUME="${RUSTDESK_DATA_VOLUME:-rustdesk-api-data}"
STATE_VOLUME="${RUSTDESK_PROVISIONER_STATE_VOLUME:-rustdesk-api-provisioner-state}"
START_PORT="${RUSTDESK_PORT:-7000}"

log() { printf '\n\033[1;34m%s\033[0m\n' "$*"; }
die() { printf '\n错误: %s\n' "$*" >&2; exit 1; }

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  die "请使用 curl ... | sudo bash 运行安装器"
fi

install_docker() {
  command -v curl >/dev/null 2>&1 || die "系统缺少 curl"
  log "未检测到 Docker，正在安装"
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y docker.io
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y docker
  elif command -v yum >/dev/null 2>&1; then
    yum install -y docker
  elif command -v apk >/dev/null 2>&1; then
    apk add --no-cache docker
  else
    die "无法自动安装 Docker，请先安装 Docker Engine 后重新运行"
  fi
}

command -v docker >/dev/null 2>&1 || install_docker
if command -v systemctl >/dev/null 2>&1; then
  systemctl enable --now docker
elif command -v service >/dev/null 2>&1; then
  service docker start || true
fi
docker info >/dev/null 2>&1 || die "Docker daemon 未运行"

GHCR_USERNAME="${GHCR_USERNAME:-}"
GHCR_TOKEN="${GHCR_TOKEN:-}"
if [[ -z "$GHCR_USERNAME" ]]; then
  [[ -r /dev/tty ]] || die "缺少 GHCR_USERNAME"
  read -r -p "GitHub 用户名: " GHCR_USERNAME </dev/tty
fi
if [[ -z "$GHCR_TOKEN" ]]; then
  [[ -r /dev/tty ]] || die "缺少 GHCR_TOKEN"
  read -r -s -p "GitHub classic PAT (read:packages): " GHCR_TOKEN </dev/tty
  printf '\n' >/dev/tty
fi
[[ "$GHCR_USERNAME" =~ ^[A-Za-z0-9-]{1,39}$ ]] || die "GitHub 用户名格式错误"
[[ -n "$GHCR_TOKEN" ]] || die "GitHub Token 不能为空"

log "登录私有 GHCR 并拉取镜像"
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io --username "$GHCR_USERNAME" --password-stdin >/dev/null
unset GHCR_TOKEN
docker pull "$API_IMAGE"
docker pull "$PROVISIONER_IMAGE"

port_in_use() {
  if command -v ss >/dev/null 2>&1; then
    ss -ltnH | awk '{print $4}' | grep -Eq "(^|:)$1$"
  elif command -v netstat >/dev/null 2>&1; then
    netstat -ltn | awk 'NR>2 {print $4}' | grep -Eq "(^|:)$1$"
  else
    docker ps --format '{{.Ports}}' | grep -Eq "(^|[ :,])$1->"
  fi
}

existing_port="$(docker inspect -f '{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' "$CONTAINER_NAME" 2>/dev/null || true)"
if [[ -n "$existing_port" ]]; then
  host_port="$existing_port"
else
  host_port="$START_PORT"
  while port_in_use "$host_port"; do host_port=$((host_port + 1)); done
fi

secret="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
docker network inspect "$NETWORK_NAME" >/dev/null 2>&1 || docker network create "$NETWORK_NAME" >/dev/null
docker volume inspect "$DATA_VOLUME" >/dev/null 2>&1 || docker volume create "$DATA_VOLUME" >/dev/null
docker volume inspect "$STATE_VOLUME" >/dev/null 2>&1 || docker volume create "$STATE_VOLUME" >/dev/null

if docker container inspect "$CONTAINER_NAME" >/dev/null 2>&1; then
  running="$(docker inspect -f '{{.State.Running}}' "$CONTAINER_NAME")"
  [[ "$running" == "true" ]] || docker start "$CONTAINER_NAME" >/dev/null
  log "检测到现有 RustDesk API 容器，保留数据并继续使用"
else
  docker rm -f "$PROVISIONER_NAME" >/dev/null 2>&1 || true
  docker run -d \
    --name "$PROVISIONER_NAME" \
    --restart unless-stopped \
    --network "$NETWORK_NAME" \
    -e "PROVISIONER_SECRET=$secret" \
    -e "MYSQL_IMAGE=$MYSQL_IMAGE" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v "$STATE_VOLUME:/state" \
    "$PROVISIONER_IMAGE" >/dev/null

  docker run -d \
    --name "$CONTAINER_NAME" \
    --restart unless-stopped \
    --network "$NETWORK_NAME" \
    -p "$host_port:80" \
    -e "RUSTDESK_DB_DRIVER=sqlite" \
    -e "RUSTDESK_DB=/var/www/data/rustdesk.db" \
    -e "RUSTDESK_INSTALL_CONFIG=/var/www/data/install.json" \
    -e "RUSTDESK_PROVISIONER_URL=http://$PROVISIONER_NAME:8080" \
    -e "RUSTDESK_PROVISIONER_SECRET=$secret" \
    -v "$DATA_VOLUME:/var/www/data" \
    "$API_IMAGE" >/dev/null
fi

server_ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
[[ -n "$server_ip" ]] || server_ip="SERVER_IP"
setup_url="http://$server_ip:$host_port/setup"

log "部署已启动"
printf '初始化地址: %s\n' "$setup_url"
printf 'SQLite 不会创建数据库容器；网页选择“自动创建 MySQL”时才会创建 MySQL 8.4。\n'
printf '完成网页初始化后，临时 provisioner 会自动删除。\n'
