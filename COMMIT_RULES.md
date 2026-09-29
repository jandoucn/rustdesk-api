# 提交规范

1. 每次提交只暂存本次任务直接相关的文件，不使用 `git add .`。
2. 提交标题使用中文，不添加 `feat:`、`fix:` 等英文前缀。
3. 标题后空一行，正文使用 `1. 2. 3.` 连续编号列出修改内容和验证结果。
4. 推送前检查暂存文件、完整提交消息和测试结果。
5. README 在项目定稿前只保留项目名称，完整文档在发布前统一整理。

仓库通过 `.githooks/commit-msg` 做本地校验，并通过 `.github/workflows/commit-policy.yml` 对 push 和 pull request 再次校验。

首次克隆后执行：

```sh
git config core.hooksPath .githooks
git config commit.template .gitmessage
```
