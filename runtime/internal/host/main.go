package host

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/php-any/loom/framework"
)

func newHandler(entry string) (http.Handler, error) {
	return appHandler(entry, nil)
}
func appHandler(entry string, services *loom.Services) (http.Handler, error) {
	app, err := loom.NewHandler(loom.Config{Entry: entry, Services: services})
	if err != nil {
		return nil, err
	}
	// 只公开 public，业务脚本、配置和数据库均不位于静态资源目录。
	files := os.DirFS("public")
	mux := http.NewServeMux()
	mux.Handle("/assets/", http.StripPrefix("/assets/", http.FileServer(http.FS(files))))
	mux.Handle("/", app)
	return mux, nil
}

// Main 从项目配置装配服务，根目录通过 --root 显式指定。
func Main() {
	root := flag.String("root", ".", "Loom 项目根目录")
	configPath := flag.String("config", "config/loom.json", "项目配置文件路径，相对于项目根目录")
	addr := flag.String("addr", "", "覆盖配置中的 HTTP 监听地址")
	entry := flag.String("entry", "", "覆盖 HTTP 入口路径，相对于项目根目录")
	database := flag.String("database", "", "覆盖 SQLite 数据库路径")
	migration := flag.String("migrate", "", "迁移操作：up、status、down（撤销最后一批）；执行后退出")
	flag.Parse()
	if err := os.Chdir(*root); err != nil {
		log.Fatal(err)
	}
	config, err := readConfig(*configPath)
	if err != nil {
		log.Fatal(err)
	}
	if *addr == "" {
		*addr = config.Address
	}
	if *entry == "" {
		*entry = config.HTTP
	}
	if *database == "" {
		*database = config.Database
	}
	if flag.NArg() > 0 {
		args := flag.Args()
		if args[0] == "command" {
			args = args[1:]
		}
		if len(args) > 0 && args[0] == "make:grpc" && *migration == "" {
			os.Exit(runMakeGRPC(context.Background(), config.GRPC, args[1:], os.Stdout, os.Stderr))
		}
		if flag.Arg(0) != "command" || *migration != "" {
			log.Print("用法：loom [宿主选项] command [命令名] [参数/选项]；不能与 --migrate 同时使用")
			os.Exit(2)
		}
		os.Exit(runApplicationConsole(config.Console, *database, flag.Args()[1:], os.Stdout, os.Stderr))
	}
	if *migration != "" {
		if *migration != "up" && *migration != "status" && *migration != "down" {
			log.Fatal("迁移操作必须为 up、status 或 down")
		}
		if err := os.MkdirAll(filepath.Dir(*database), 0700); err != nil {
			log.Fatal(err)
		}
		s, err := loom.OpenServices(*database)
		if err != nil {
			log.Fatal(err)
		}
		defer s.Close()
		plan, err := studioMigrations()
		if err != nil {
			log.Fatal(err)
		}
		if *migration != "status" {
			if err = s.Migrate(context.Background(), "studio", plan, *migration == "down"); err != nil {
				log.Fatal(err)
			}
		}
		status, err := s.MigrationStatus(context.Background(), "studio", plan)
		if err != nil {
			log.Fatal(err)
		}
		if err = json.NewEncoder(os.Stdout).Encode(status); err != nil {
			log.Fatal(err)
		}
		return
	}
	if err := os.MkdirAll(filepath.Dir(*database), 0700); err != nil {
		log.Fatal(err)
	}
	services, err := openStudio(*database)
	if err != nil {
		log.Fatal(err)
	}
	defer services.Close()
	handler, err := appHandler(*entry, services)
	if err != nil {
		log.Fatal(err)
	}
	server := &http.Server{Addr: *addr, Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 15 * time.Second, WriteTimeout: 20 * time.Second, IdleTimeout: 60 * time.Second}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	// Hijacked WebSocket connections do not participate in http.Server.Shutdown.
	// Propagate shutdown cancellation so Loom closes their sockets as well.
	server.BaseContext = func(net.Listener) context.Context { return ctx }
	worker, err := loom.NewHandler(loom.Config{Entry: config.Jobs, Services: services})
	if err != nil {
		log.Fatal(err)
	}
	stopProtocols, err := startProtocols(ctx, config, services, stop)
	if err != nil {
		log.Fatal(err)
	}
	defer stopProtocols()
	workerDone := make(chan struct{})
	go func() {
		defer close(workerDone)
		services.Work(ctx, worker.RunJob, func(err error) { log.Printf("worker: %v", err) })
	}()
	done := make(chan struct{})
	go func() {
		defer close(done)
		<-ctx.Done()
		drain, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		if err := server.Shutdown(drain); err != nil {
			log.Printf("shutdown: %v", err)
			_ = server.Close()
		}
	}()
	log.Printf("Loom 已启动：http://%s", *addr)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
	stop()
	<-done
	<-workerDone
}
