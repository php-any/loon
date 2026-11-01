package host

import (
	_ "time/tzdata" // 确保没有 zoneinfo 数据库的机器也能使用命名时区。

	_ "github.com/go-sql-driver/mysql"

	"github.com/php-any/loom/runtime/internal/extensions"
	"github.com/php-any/origami/data"
	"github.com/php-any/origami/std"
	netannotation "github.com/php-any/origami/std/net/annotation"
	"github.com/php-any/origami/std/net/grpc"
	"github.com/php-any/origami/std/net/http"
	"github.com/php-any/origami/std/net/tcp"
	"github.com/php-any/origami/std/net/websocket"
	"github.com/php-any/origami/std/php"
	"github.com/php-any/origami/std/php/redis"
	"github.com/php-any/origami/std/system"
)

func loadModules(vm data.VM) {
	std.Load(vm)
	php.Load(vm)
	redis.Load(vm)
	http.Load(vm)
	websocket.Load(vm)
	tcp.Load(vm)
	grpc.Load(vm)
	netannotation.Load(vm)
	system.Load(vm)
	extensions.Load(vm)
}
