<?php

declare(strict_types=1);

/**
 * gRPC 服务及代码生成配置，分别由 grpc:serve 和 make:grpc 等命令读取。
 * 服务启动时会合并 generated/grpc/routes.json 中登记的协议文件与导入目录，并去重。
 * 网络限制在 config/network.php 的 grpc 分组配置；修改此文件后需重启 gRPC 服务。
 */
return [
    /**
     * 监听地址，类型为“主机:端口”字符串，不带协议前缀。
     * LOOM_GRPC_ADDRESS 非空时优先使用，否则默认 127.0.0.1:18089。
     * 示例：LOOM_GRPC_ADDRESS=0.0.0.0:50051；本机调试可用 127.0.0.1:50051。
     * 0.0.0.0 监听全部 IPv4 网卡；.env 中同名值覆盖进程环境变量。
     */
    'address' => (getenv('LOOM_GRPC_ADDRESS') ?: '127.0.0.1:18089'),
    /**
     * 每次 RPC 调用加载的 PHP 入口，字符串路径，相对项目根目录或使用绝对路径。
     * 默认入口注册模块服务，再通过 routes/grpc.php 与生成的清单分发调用。
     * 示例：'entry' => 'bootstrap/grpc.php'（需先创建脚本）。
     * 自定义入口可使用 $scope、$services、$config，必须返回一个接收数组的可调用对象。
     * 输入包含 method（RPC 完整路径）和 request（JSON 解码值）；
     * 返回 ['data' => $result]，或 ['error' => ['code' => 'invalid_argument', 'message' => '原因']]。
     */
    'entry' => 'framework/bootstrap/grpc.php',
    /**
     * .proto 文件及其 import 的搜索目录，类型为字符串数组；相对路径基于项目根目录。
     * 示例：'import_paths' => ['proto', 'vendor-proto']，也可填写绝对目录。
     * proto_files 中的文件名相对于这些目录解析；避免不同目录出现同名且有歧义的协议文件。
     */
    'import_paths' => ['proto'],
    /**
     * 显式加载的协议入口文件，类型为字符串数组，文件必须可在 import_paths 中找到。
     * 示例：'proto_files' => ['release.proto', 'billing/invoice.proto']。
     * 可设为 []，仅使用生成清单提供的文件；前提是清单已包含所需服务定义。
     * 此列表不等于业务处理器注册；实现还需通过 routes/grpc.php 或生成清单关联。
     */
    'proto_files' => ['release.proto'],
    /**
     * 是否开放 gRPC 服务反射，类型为布尔值，可选 true 或 false，不能写成字符串 'false'。
     * true 允许支持反射的调试客户端查询服务结构；false 关闭反射，客户端需自行提供协议。
     * 示例：'reflection' => false；此项省略时框架按 false 处理。
     */
    'reflection' => true,
    /**
     * 生成 PHP 类名时移除的 proto 包名前缀，类型为字符串；仅影响代码生成命名。
     * 例如服务 app.billing.InvoiceService 配置 'app' 后生成 App\Grpc\Services\Billing\InvoiceService。
     * 可配置其他实际包名前缀，例如 'company.api'；设为 '' 则保留完整包名层级。
     * 只有匹配“前缀.”时才移除；不会修改协议里的包名或 RPC 路径。
     * 示例：'package_prefix' => ''；更改后需检查生成路径、业务实现及路由清单是否一致。
     */
    'package_prefix' => 'app',
];
