# 历史上游补丁

这些 Python 文件是先前开发阶段对本机 Origami 源码实施修改的一次性脚本，原存于被忽略的 build 目录。它们包含当时的本机绝对路径，保留仅供历史追溯，不是可重复执行的安装或测试工具，不应直接运行。

Loom 的正常验证入口是 `tests/run-all.ps1`，运行时能力要求见 `docs/runtime-requirements.md`。通用 stream 回归用例已独立移入 `framework/tests/`。旧 Loom 宿主二进制保留在本机 `storage/archive/legacy-runtime/`，不参与启动。
