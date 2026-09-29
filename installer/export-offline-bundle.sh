#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_IMAGE="${RUSTDESK_API_IMAGE:-crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api:latest}"
PROVISIONER_IMAGE="${RUSTDESK_PROVISIONER_IMAGE:-crpi-7xxhnenx29e9prnb.cn-hongkong.personal.cr.aliyuncs.com/ollydocker/rustdesk-api:provisioner-latest}"
PLATFORM="${RUSTDESK_PLATFORM:-linux/amd64}"
REGISTRY_USERNAME="${REGISTRY_USERNAME:-yanolly}"
REGISTRY_HOST="${API_IMAGE%%/*}"
OUTPUT="${1:-$ROOT_DIR/dist/rustdesk-api-offline-linux-amd64-latest.tar}"

log() { printf '\n\033[1;34m%s\033[0m\n' "$*"; }
die() { printf '\n错误: %s\n' "$*" >&2; exit 1; }
sha256_file() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1"
  else
    shasum -a 256 "$1"
  fi
}

command -v docker >/dev/null 2>&1 || die "未检测到 Docker"
docker info >/dev/null 2>&1 || die "Docker daemon 未运行"
[[ -n "$REGISTRY_USERNAME" ]] || die "ACR 用户名不能为空"

REGISTRY_PASSWORD="${REGISTRY_PASSWORD:-}"
if [[ -z "$REGISTRY_PASSWORD" ]]; then
  [[ -r /dev/tty ]] || die "缺少 REGISTRY_PASSWORD，且当前终端无法安全读取"
  read -r -s -p "ACR 固定密码: " REGISTRY_PASSWORD </dev/tty
  printf '\n' >/dev/tty
fi
[[ -n "$REGISTRY_PASSWORD" ]] || die "ACR 固定密码不能为空"

tmp_dir="$(mktemp -d)"
docker_config="$tmp_dir/docker-config"
bundle_dir="$tmp_dir/bundle"
trap 'rm -rf "$tmp_dir"' EXIT
mkdir -p "$docker_config" "$bundle_dir" "$(dirname "$OUTPUT")"

log "临时登录阿里云 ACR"
printf '%s' "$REGISTRY_PASSWORD" | docker --config "$docker_config" login "$REGISTRY_HOST" \
  --username "$REGISTRY_USERNAME" --password-stdin >/dev/null
unset REGISTRY_PASSWORD

log "强制拉取 ACR 最新镜像 ($PLATFORM)"
docker --config "$docker_config" pull --platform "$PLATFORM" "$API_IMAGE"
docker --config "$docker_config" pull --platform "$PLATFORM" "$PROVISIONER_IMAGE"

log "导出并压缩两个镜像"
docker save "$API_IMAGE" "$PROVISIONER_IMAGE" | gzip -1 > "$bundle_dir/images.tar.gz"
cp "$ROOT_DIR/installer/install.sh" "$bundle_dir/install.sh"
cp "$ROOT_DIR/installer/install-offline-bundle.sh" "$bundle_dir/install-offline-bundle.sh"
chmod +x "$bundle_dir/install.sh" "$bundle_dir/install-offline-bundle.sh"

{
  printf 'exported_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  printf 'platform=%s\n' "$PLATFORM"
  for image in "$API_IMAGE" "$PROVISIONER_IMAGE"; do
    docker image inspect "$image" --format \
      'image={{index .RepoTags 0}} id={{.Id}} os={{.Os}} architecture={{.Architecture}} digests={{join .RepoDigests ","}}'
  done
} > "$bundle_dir/manifest.txt"

(
  cd "$bundle_dir"
  sha256_file images.tar.gz > SHA256SUMS
  sha256_file install.sh >> SHA256SUMS
  sha256_file install-offline-bundle.sh >> SHA256SUMS
)

tar -C "$bundle_dir" -cf "$OUTPUT" .
output_dir="$(cd "$(dirname "$OUTPUT")" && pwd)"
output_name="$(basename "$OUTPUT")"
(
  cd "$output_dir"
  sha256_file "$output_name" > "$output_name.sha256"
)

log "离线包已生成"
printf '文件: %s\n' "$OUTPUT"
printf '大小: %s\n' "$(du -h "$OUTPUT" | awk '{print $1}')"
printf '校验: %s.sha256\n' "$OUTPUT"
printf '该包来自本次重新拉取的 ACR latest，不复用旧镜像。\n'
