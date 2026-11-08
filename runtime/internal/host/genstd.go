package host

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/php-any/loom/runtime/internal/stubs"
	"github.com/php-any/origami/data"
	"github.com/php-any/origami/parser"
	"github.com/php-any/origami/runtime"
	laravelframework "github.com/php-any/origami/std/laravel/framework"
)

const genStdHelp = `从宿主已加载的标准库、原生函数和 Origami 框架扩展生成 PHP 伪代码，供 IDE 补全。

用法:
  loom gen-std
  loom gen-std -o <目录>

默认写入项目根目录的 .zy/std。-o 的相对路径按当前工作目录解析。
这些文件只给编辑器索引，运行时不会加载它们。
`

func generateStd(root string, args []string) int {
	output, help, err := parseGenStdArgs(args)
	if err != nil {
		fmt.Fprintln(os.Stderr, err.Error())
		return 1
	}
	if help {
		fmt.Fprint(os.Stdout, genStdHelp)
		return 0
	}
	if root == "" {
		found, err := discoverProjectRoot()
		if err != nil {
			fmt.Fprintln(os.Stderr, err.Error())
			return 1
		}
		root = found
	}
	if output == "" {
		if root == "" {
			fmt.Fprintln(os.Stderr, "gen-std: 找不到项目根目录。请在包含 loom.php 的目录中运行，或用 -o 指定输出目录。")
			return 1
		}
		output = filepath.Join(root, ".zy", "std")
	} else if !filepath.IsAbs(output) {
		cwd, err := os.Getwd()
		if err != nil {
			fmt.Fprintln(os.Stderr, err.Error())
			return 1
		}
		output = filepath.Join(cwd, output)
	}

	vm := runtime.NewVM(parser.NewParser()).(*runtime.VM)
	loadStubModules(vm)
	if err := stubs.Generate(vm, output); err != nil {
		fmt.Fprintln(os.Stderr, err.Error())
		return 1
	}
	return 0
}

// 框架扩展只参与伪代码生成。运行脚本时不注册这些类。
func loadStubModules(vm data.VM) {
	loadModules(vm)
	laravelframework.Load(vm)
}

func parseGenStdArgs(args []string) (string, bool, error) {
	output := ""
	for i := 0; i < len(args); i++ {
		arg := args[i]
		switch {
		case arg == "-h" || arg == "--help":
			return "", true, nil
		case arg == "-o" || arg == "--output":
			if i+1 >= len(args) || strings.HasPrefix(args[i+1], "-") {
				return "", false, fmt.Errorf("gen-std: %s 缺少输出目录", arg)
			}
			i++
			output = args[i]
		case strings.HasPrefix(arg, "--output="):
			output = strings.TrimPrefix(arg, "--output=")
			if output == "" {
				return "", false, fmt.Errorf("gen-std: --output 缺少输出目录")
			}
		case strings.HasPrefix(arg, "-o="):
			output = strings.TrimPrefix(arg, "-o=")
			if output == "" {
				return "", false, fmt.Errorf("gen-std: -o 缺少输出目录")
			}
		default:
			return "", false, fmt.Errorf("gen-std: 未知参数 %s", arg)
		}
	}
	return output, false, nil
}

func discoverProjectRoot() (string, error) {
	cwd, err := os.Getwd()
	if err != nil {
		return "", err
	}
	if root := projectRoot(cwd); root != "" {
		return root, nil
	}
	if env := os.Getenv("LOOM_ROOT"); env != "" {
		if !filepath.IsAbs(env) {
			env = filepath.Join(cwd, env)
		}
		if root := projectRoot(env); root != "" {
			return root, nil
		}
	}
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	return projectRoot(filepath.Dir(exe)), nil
}
