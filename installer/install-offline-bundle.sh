#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_IMAGE="${RUSTDESK_API_IMAGE:-crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api:latest}"
PROVISIONER_IMAGE="${RUSTDESK_PROVISIONER_IMAGE:-crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api:provisioner-latest}"

die() { printf '\n错误: %s\n' "$*" >&2; exit 1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || die "请使用 sudo 运行"
command -v docker >/dev/null 2>&1 || die "请先安装 Docker Engine"
docker info >/dev/null 2>&1 || die "Docker daemon 未运行"

cd "$ROOT_DIR"
sha256sum -c SHA256SUMS
gzip -dc images.tar.gz | docker load
docker image inspect "$API_IMAGE" >/dev/null 2>&1 || die "镜像导入后仍找不到: $API_IMAGE"
docker image inspect "$PROVISIONER_IMAGE" >/dev/null 2>&1 || die "镜像导入后仍找不到: $PROVISIONER_IMAGE"

exec env RUSTDESK_OFFLINE=1 bash "$ROOT_DIR/install.sh"
