package loom

import (
	"github.com/php-any/origami/parser"
	"github.com/php-any/origami/runtime"
	"github.com/php-any/origami/std"
	"github.com/php-any/origami/std/php"
	"path/filepath"
	"testing"
)

// 独立项目直接执行 PHP 契约测试，不再借助 Origami 仓库的 zy.go。
func TestPHPContracts(t *testing.T) {
	for _, name := range []string{"loom_framework_test.php", "loom_routes_test.php", "loom_middleware_test.php", "loom_view_test.php", "loom_console_test.php"} {
		t.Run(name, func(t *testing.T) {
			base := runtime.NewVM(parser.NewParser()).(*runtime.VM)
			std.Load(base)
			php.Load(base)
			vm := runtime.NewRequestVM(base).(*runtime.RequestVM)
			restore := vm.Activate()
			defer restore()
			path, err := filepath.Abs(filepath.Join("..", "..", "tests", "php", name))
			if err != nil {
				t.Fatal(err)
			}
			if _, control := vm.LoadAndRun(path); control != nil {
				t.Fatal(control.AsString())
			}
		})
	}
}
