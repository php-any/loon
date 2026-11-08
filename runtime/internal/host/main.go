// host 包嵌入 Origami，分发 Loom 命令或独立脚本。
package host

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/php-any/origami/parser"
	"github.com/php-any/origami/runtime"
)

// Run 是进程入口：Origami 在初始化 PHP argv 时读取 os.Args。
func Run(args []string) int {
	if len(args) > 0 && args[0] == "gen-std" {
		return generateStd("", args[1:])
	}
	cwd, err := os.Getwd()
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	executable, err := os.Executable()
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	script, scriptArgs, err := resolve(args, cwd, executable, os.Getenv("LOOM_ROOT"))
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	info, err := os.Stat(script)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	if info.IsDir() {
		fmt.Fprintln(os.Stderr, "Script is a directory:", script)
		return 1
	}
	os.Args = append([]string{executable, script}, scriptArgs...)
	p := parser.NewParser()
	vm := runtime.NewVM(p).(*runtime.VM)
	loadModules(vm)
	registerApplicationClasses(vm, script)
	defer vm.RunShutdownCallbacks()
	_, control := vm.LoadAndRun(script)
	if control != nil {
		_, control = vm.HandleUnhandledException(control)
	}
	if control == nil {
		return 0
	}
	if exit, ok := control.(interface {
		IsExit() bool
		GetCode() int
	}); ok && exit.IsExit() {
		return exit.GetCode()
	}
	p.ShowControl(control)
	return 1
}

func resolve(args []string, cwd, executable, root string) (string, []string, error) {
	if len(args) > 0 {
		ext := strings.ToLower(filepath.Ext(args[0]))
		candidate := args[0]
		if !filepath.IsAbs(candidate) {
			candidate = filepath.Join(cwd, candidate)
		}
		info, statErr := os.Stat(candidate)
		if ext == ".php" || ext == ".zy" || strings.ContainsAny(args[0], `/\`) || (statErr == nil && !info.IsDir()) {
			path := args[0]
			if !filepath.IsAbs(path) {
				path = filepath.Join(cwd, path)
			}
			return path, args[1:], nil
		}
	}
	if len(args) == 1 && (args[0] == "--help" || args[0] == "-h") {
		args = []string{"list"}
	}
	if root != "" {
		if !filepath.IsAbs(root) {
			root = filepath.Join(cwd, root)
		}
		return filepath.Join(root, "loom.php"), args, nil
	}
	// 优先使用选定的项目，其次使用可执行文件旁的部署目录。
	for _, base := range []string{cwd, filepath.Dir(executable)} {
		for dir := base; ; dir = filepath.Dir(dir) {
			entry := filepath.Join(dir, "loom.php")
			if info, err := os.Stat(entry); err == nil && !info.IsDir() {
				return entry, args, nil
			}
			if filepath.Dir(dir) == dir {
				break
			}
		}
	}
	return "", nil, fmt.Errorf("cannot find loom.php; set LOOM_ROOT or pass a .php/.zy script path")
}

// Origami 按类路径加载业务类。引导脚本不注册命名空间，也不改工作目录。
func registerApplicationClasses(vm *runtime.VM, script string) {
	root := projectRoot(filepath.Dir(script))
	if root == "" {
		return
	}
	for _, mapping := range []struct {
		namespace string
		directory string
	}{
		{"App", "app"},
		{`Generated\Grpc`, filepath.Join("generated", "grpc")},
	} {
		path := filepath.Join(root, mapping.directory)
		info, err := os.Stat(path)
		if err != nil || !info.IsDir() {
			continue
		}
		vm.AddNamespace(mapping.namespace, path)
	}
}

func projectRoot(dir string) string {
	for {
		entry := filepath.Join(dir, "loom.php")
		info, err := os.Stat(entry)
		if err == nil && !info.IsDir() {
			return dir
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			return ""
		}
		dir = parent
	}
}
