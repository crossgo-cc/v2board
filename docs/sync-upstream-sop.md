# 同步上游 SOP（LLM 编程代理通用）

目标：将 `upstream/master` 同步到本项目分支，并在冲突处理中以本项目现有功能和定制为优先，同时吸收上游明确有价值且不破坏本项目行为的修复。

适用对象：Codex、Claude Code、Cursor Agent、Continue、Aider，以及其他能读取仓库、执行 Git 命令、编辑文件的 LLM 编程代理。

不同代理的工具名称可能不同。执行时以“当前环境能运行的命令和可用工具”为准；不要把某个代理专属工具当成前提。若文档中的命令不可用，使用等价检查，并在汇报中说明替代方式。

## 成功标准

- 当前分支已包含 `upstream/master`。
- 无未解决冲突标记。
- 本项目已有功能、定制逻辑、配置约定未被上游覆盖。
- 合并提交已生成。
- 基础检查通过；无法运行的检查必须明确说明原因。
- 未跟任务无关的未跟踪文件或本地改动不得被提交。

## 执行步骤

### 1. 确认当前状态

```bash
git status --short --branch
git remote -v
git branch --show-current
```

要求：

- 记录当前分支。
- 记录未提交、未跟踪文件。
- 不要自动删除、重置、覆盖用户已有改动。
- 如存在脏工作区，先判断是否会影响合并；不确定时停止并说明风险。
- 如果代理有代码库检索能力，先用它理解项目结构、测试命令和本项目定制点；如果没有，使用本地搜索命令获取等价上下文。

### 2. 获取上游

```bash
git fetch upstream --prune
```

如果没有 `upstream`，先查看远端并说明缺失，不要自行猜测远端地址。

### 3. 分析差异

```bash
git rev-list --left-right --count HEAD...upstream/master
git log --oneline --decorate --left-right --cherry-pick HEAD...upstream/master
git diff --name-status HEAD..upstream/master
```

要求：

- 说明本地比上游多几个提交，上游比本地多几个提交。
- 标出高风险文件，例如 auth、支付、订阅协议、数据库、配置、队列、统计逻辑。
- 不要直接 `reset` 到上游。

### 4. 执行合并

优先使用 merge，保留本项目历史：

```bash
git merge --no-commit --no-ff upstream/master
```

如果出现冲突，不要使用 `git checkout --ours` 或 `git checkout --theirs` 粗暴解决。

### 5. 分析冲突

```bash
git diff --name-only --diff-filter=U
git diff --cc <file>
git show :1:<file>   # merge base
git show :2:<file>   # ours，本项目
git show :3:<file>   # theirs，上游
```

冲突处理原则：

- “本项目优先”不是盲目保留 ours。
- 本项目已有功能、业务约定、字段兼容、定制逻辑优先保留。
- 上游新增的安全修复、兼容性修复、协议支持，如果不破坏本项目，应合入。
- 如果双方都实现同一功能，按本项目数据结构和调用链整合。
- 不相关代码不要顺手重构。
- 不确定语义时，先查本项目上下文；涉及协议或库行为时查官方文档。

### 6. 解决冲突后检查

先使用代理自带代码搜索能力，确认仓库中没有残留冲突标记。可搜索匹配行首七个 `<`、`=` 或 `>` 的模式：

```text
^(<{7}|={7}|>{7})
```

然后运行基础文本检查：

```bash
git diff --check
git diff --cached --check
```

如果代理没有代码搜索能力，可用 `git grep`、`grep` 等本地命令做等价检查。

然后标记冲突解决：

```bash
git add <resolved-files>
```

### 7. 验证

按项目可用命令运行：

```bash
php -l <changed-php-file>
php artisan test
./vendor/bin/phpunit
```

如果环境缺少依赖或命令不可用，必须说明：

- 哪个命令无法运行。
- 失败原因。
- 已做的替代检查，例如冲突标记搜索、`git diff --check`、静态审查。
- 如果代理无法启动服务、浏览器或交互式命令，不要伪造验证结果；只汇报已实际执行的检查。

### 8. 完成合并提交

```bash
git commit -m "Merge upstream/master"
```

### 9. 最终确认

```bash
git merge-base --is-ancestor upstream/master HEAD
git status --short --branch
git log --oneline --decorate --max-count=5
```

完成条件：

- `git merge-base --is-ancestor upstream/master HEAD` 返回成功。
- `git status` 无冲突状态。
- 只剩用户原本已有的未跟踪或未提交文件时，要明确说明没有动它们。

### 10. 推送

```bash
git push origin <current-branch>
```

如果 403 或认证失败：

- 不要改写提交。
- 说明当前凭据账号和权限问题。
- 让用户切换有写权限的 GitHub 凭据后再重试。

## 禁止操作

- 禁止 `git reset --hard`。
- 禁止无确认删除用户文件。
- 禁止把未跟任务相关的未跟踪文件加入提交。
- 禁止为了解冲突大面积重构。
- 禁止未看三方差异就选择 ours/theirs。
- 禁止把“没有冲突标记”当作“功能一定没问题”。
- 禁止因为代理工具限制而跳过说明；能力缺口必须写进最终汇报。

## 汇报格式

完成后必须汇报：

- 合并提交 hash。
- 冲突文件列表。
- 每个冲突的处理原则。
- 验证命令和结果。
- 未运行的验证及原因。
- 当前分支与远端状态。
