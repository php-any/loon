# 文件存储

Loom 提供可选的 `config/filesystems.php`，由 `Options::bindOptional` 绑定到 `Loom\Filesystem\FilesystemOptions`。配置文件缺失时使用内置默认：本地 `storage/app` 与对外 `storage/app/public`（URL 前缀 `/files`）。

## 磁盘 API

容器登记单例 `Loom\Filesystem\Filesystem`。业务代码注入后使用 `$files->disk()` 或 `$files->disk('public')`。各磁盘支持：

- `put` / `get` / `exists` / `delete` / `copy` / `move`
- `files`（单层目录列举）
- `makeDirectory`
- `path`（绝对本地路径）
- `url`（仅配置了 `url` 的磁盘，例如 `/files/avatars/x.png`）
- `response`（控制器内鉴权后下发私有文件，4 MiB 上限）

对象键统一使用 `/`，`put` 会自动创建父目录。非法键（空、含 `..`、绝对路径、盘符、空段、NUL）抛出 `LogicException`。`get` 在文件不存在时抛出 `RuntimeException`。

## 上传

请求体仍由宿主一次性读入字符串，大小受 `network.http.maxBodyBytes` 限制。不使用 `$_FILES`。

- `$request->form()` 解析 `application/x-www-form-urlencoded` 或 `multipart/form-data` 字段，返回 `Input`。
- `$request->file('avatar')` 返回第一个 `UploadedFile` 或 `null`。
- `$request->files('photos')` 返回列表。

`UploadedFile` 提供客户端文件名、声明的 Content-Type、大小、`contents()`，以及 `store($directory, $disk)`（随机文件名与安全扩展名）。HTTP 请求作用域内自动绑定 `Filesystem`，否则 `store` 会报错。

错误的 multipart 或缺少 boundary 返回 400；`form()` 在不支持的媒体类型下返回 415。

## 对外文件

磁盘点 `serve => true` 且配置了 `url` 时，`HttpServer` 会在内核与中间件之前按前缀提供文件（与 `/assets/` 相同语义）：仅 GET/HEAD、4 MiB 上限、路径不能越出磁盘根目录、按扩展名设置 Content-Type，`.php` 与未知类型为 `application/octet-stream` 且不执行。

`build()` 之前可通过 `$builder->filesystems->extend('s3', ...)` 注册自定义驱动工厂；v1 仅内置 `local` 驱动。

## 监控

在已 `begin()` 的 Monitoring 会话中，且 `config/monitoring.php` 的 `watchers.filesystem.enabled` 为真时，磁盘与上传会写入 `filesystem.*` 条目（例如 `filesystem.write`、`filesystem.read`、`filesystem.delete`、`filesystem.upload`、`filesystem.serve`）。字段包含磁盘名、对象键或表单字段名、以及已知时的 `size`。**从不**记录文件正文或上传字节。会话未激活或 watcher 关闭时不写入。仪表盘提供 `/monitoring/filesystem` 列表（`driver=database` 时），会话详情时间线中同样可见。
