#!/usr/bin/env bash
set -Eeuo pipefail

message_file="${1:-}"
[[ -n "$message_file" && -f "$message_file" ]] || {
  printf '用法: %s <提交消息文件>\n' "$0" >&2
  exit 2
}

subject="$(sed -n '1p' "$message_file")"
[[ -n "$subject" ]] || {
  printf '提交标题不能为空。\n' >&2
  exit 1
}

if LC_ALL=C grep -Eq '[A-Za-z]' <<<"$subject"; then
  printf '提交标题必须使用中文，不得包含英文前缀: %s\n' "$subject" >&2
  exit 1
fi

if ! grep -Eq '[一-龥]' <<<"$subject"; then
  printf '提交标题必须包含中文: %s\n' "$subject" >&2
  exit 1
fi

if [[ -n "$(sed -n '2p' "$message_file")" ]]; then
  printf '提交标题后必须保留一个空行。\n' >&2
  exit 1
fi

details=()
while IFS= read -r detail; do
  [[ -n "$detail" ]] && details[${#details[@]}]="$detail"
done < <(sed -n '3,$p' "$message_file")
if (( ${#details[@]} == 0 )); then
  printf '提交正文必须用 1. 2. 3. 列出修改内容和验证结果。\n' >&2
  exit 1
fi

expected=1
for detail in "${details[@]}"; do
  if [[ ! "$detail" =~ ^${expected}\.\ .+ ]]; then
    printf '提交正文第 %d 项格式错误，应以 "%d. " 开头: %s\n' "$expected" "$expected" "$detail" >&2
    exit 1
  fi
  expected=$((expected + 1))
done
