<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用的组合根，对应 ASP.NET Core 的 WebApplicationBuilder。构造时读取环境文件和 config/，并登记框架默认服务；
 * 应用在 build() 之前通过 $services 追加或覆盖登记。
 *
 * build() 时扫描 config/modules.php 里的模块：带 Service 注解的类按注解的生命周期补登记（应用已登记的标识不覆盖），
 * 控制器路由进入路由表，ConsoleCommand 类进入命令表，Timer 类交给 TimerRunner。
 *
 * @example $builder = Application::createBuilder(__DIR__); $builder->services->addSingleton(Clock::class); $app = $builder->build();
 */
final class ApplicationBuilder
{
    public readonly ServiceCollection $services;
    public readonly Config $configuration;
    public readonly \Loom\MonitoringBuilder $monitoring;
    public readonly \Loom\Filesystem\FilesystemBuilder $filesystems;
    public readonly \Loom\Events\EventsBuilder $events;
    private \Loom\Events\EventsRegistration $eventsRegistration;
    private bool $built = false;
    private ?\Closure $onStop = null;

    /**
     * @param string $root 项目根目录。
     * @param ?string $environmentFile 先加载的 .env 文件；null 表示不加载。
     */
    public function __construct(string $root, ?string $environmentFile = null)
    {
        if ($environmentFile !== null) {
            Environment::load($environmentFile);
        }
        $this->configuration = new Config($root);
        $this->configuration->load();
        $this->services = new ServiceCollection();
        $services = $this->services;
        $services->addInstance(Config::class, $this->configuration);
        $services->addSingleton(Options::class);
        $services->addSingleton(Network::class);
        $services->addSingleton(Process::class);
        $services->addSingleton(StopSignal::class);
        $services->addSingleton(Host::class);
        $services->addSingleton(HttpServer::class);
        $services->addSingleton(TcpServer::class);
        $services->addSingleton(GrpcServer::class);
        $services->addSingleton(JobDispatcher::class);
        $services->configure(HttpServerOptions::class, 'http');
        $services->tryAddSingleton(\Loom\Logging\DiaryOptions::class, static function (ServiceResolver $resolver): \Loom\Logging\DiaryOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\Logging\DiaryOptions::class, 'diary');
            if (!($bound instanceof \Loom\Logging\DiaryOptions)) {
                throw new \LogicException('DiaryOptions binding must return DiaryOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\Logging\JournalWriter::class, static function (ServiceResolver $resolver): \Loom\Logging\JournalWriter {
            $options = $resolver->get(\Loom\Logging\DiaryOptions::class);
            return match ($options->writer) {
                'file' => new \Loom\Logging\FileJournalWriter(
                    $options->file !== ''
                        ? $options->file
                        : $resolver->get(Config::class)->path('storage/logs/diary.log'),
                ),
                'null' => new \Loom\Logging\NullJournalWriter(),
                default => new \Loom\Logging\StderrJournalWriter(),
            };
        });
        $services->tryAddSingleton(\Loom\Logging\JournalFormatter::class, static function (ServiceResolver $resolver): \Loom\Logging\JournalFormatter {
            $options = $resolver->get(\Loom\Logging\DiaryOptions::class);
            return match ($options->formatter) {
                'text', 'plain' => new \Loom\Logging\PlainTextJournalFormatter(),
                default => new \Loom\Logging\JsonLineJournalFormatter(),
            };
        });
        $services->tryAddSingleton(\Loom\Logging\Diary::class);
        $services->addHostedService(TimerHostedService::class);
        $services->addScoped(Database::class);
        $services->addScoped(RequestContext::class);
        $services->addScoped(\Loom\Monitoring\CorrelationId::class);
        $services->tryAddScoped(\Loom\Monitoring\Monitoring::class, \Loom\Monitoring\LoggingMonitoring::class);
        $monitoringRegistration = new \Loom\Monitoring\MonitoringRegistration();
        $services->addInstance(\Loom\Monitoring\MonitoringRegistration::class, $monitoringRegistration);
        $this->monitoring = new \Loom\MonitoringBuilder($services, $monitoringRegistration);
        $filesystemRegistration = new \Loom\Filesystem\FilesystemRegistration();
        $services->addInstance(\Loom\Filesystem\FilesystemRegistration::class, $filesystemRegistration);
        $this->filesystems = new \Loom\Filesystem\FilesystemBuilder($filesystemRegistration);
        $this->eventsRegistration = new \Loom\Events\EventsRegistration();
        $services->addInstance(\Loom\Events\EventsRegistration::class, $this->eventsRegistration);
        $this->events = new \Loom\Events\EventsBuilder($this->eventsRegistration);
        $services->tryAddSingleton(\Loom\Filesystem\FilesystemOptions::class, static function (ServiceResolver $resolver): \Loom\Filesystem\FilesystemOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\Filesystem\FilesystemOptions::class, 'filesystems');
            if (!($bound instanceof \Loom\Filesystem\FilesystemOptions)) {
                throw new \LogicException('FilesystemOptions binding must return FilesystemOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\Filesystem\Filesystem::class, static function (ServiceResolver $resolver): \Loom\Filesystem\Filesystem {
            return new \Loom\Filesystem\Filesystem(
                $resolver->get(Config::class),
                $resolver->get(\Loom\Filesystem\FilesystemOptions::class),
                $resolver->get(\Loom\Filesystem\FilesystemRegistration::class),
            );
        });
        $services->tryAddSingleton(\Loom\Monitoring\MonitoringOptions::class, static function (ServiceResolver $resolver): \Loom\Monitoring\MonitoringOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\Monitoring\MonitoringOptions::class, 'monitoring');
            if (!($bound instanceof \Loom\Monitoring\MonitoringOptions)) {
                throw new \LogicException('MonitoringOptions binding must return MonitoringOptions');
            }
            $bound->finalize();
            return $bound;
        });
        $services->tryAddSingleton(\Loom\Monitoring\DatabaseMonitoringStore::class);
        $services->tryAddSingleton(\Loom\Monitoring\MonitoringRepository::class);
        $services->tryAddTransient(\Loom\Monitoring\MonitoringDashboard::class);
    }

    /**
     * 登记长驻进程收到停止信号时要执行的回调。可在 build() 之前调用；未登记则直接退出。
     *
     * @example $builder->onStop(static function (): void { fwrite(STDERR, "bye\n"); });
     */
    public function onStop(callable $handler): self
    {
        $this->onStop = $handler(...);
        return $this;
    }

    /**
     * 扫描模块、补齐模块服务，生成根容器和 Application。只能调用一次。
     */
    public function build(): Application
    {
        if ($this->built) {
            throw new \LogicException('The application has already been built');
        }
        $this->built = true;
        $services = $this->services;
        $registration = $this->monitoring->registration();
        if (!$registration->monitoringReplaced() && count($services->descriptors(\Loom\Monitoring\Monitoring::class)) <= 1) {
            $services->addScoped(\Loom\Monitoring\Monitoring::class, static function (ServiceResolver $resolver): \Loom\Monitoring\Monitoring {
                $options = $resolver->get(\Loom\Monitoring\MonitoringOptions::class);
                $configured = new \Loom\Monitoring\ConfigurableMonitoring(
                    $options,
                    $resolver->get(\Loom\Monitoring\MonitoringRegistration::class),
                    $resolver->get(\Loom\Logging\Diary::class),
                    $resolver->get(\Loom\Monitoring\DatabaseMonitoringStore::class),
                );
                $configured->bindScope($resolver->get(ServiceScope::class));
                return $configured;
            });
        }
        $discovery = ModuleDiscovery::configured($this->configuration);
        foreach ($discovery->eventListeners() as $event => $listeners) {
            foreach ($listeners as [$class, $method]) {
                $this->eventsRegistration->discover($event, $class, $method);
            }
        }
        $listenerMap = $this->eventsRegistration->listeners();
        $services->tryAddScoped(\Loom\Events\EventDispatcher::class, static function (ServiceResolver $resolver) use ($listenerMap): \Loom\Events\EventDispatcher {
            return new \Loom\Events\EventDispatcher($listenerMap, $resolver);
        });
        foreach ($discovery->services() as $id => $class) {
            $services->tryAdd(new ServiceDescriptor($id, ServiceLifetime::from($discovery->serviceLifetime($id)), $class));
        }
        $router = $discovery->router();
        $monitoringOptions = (new Options($this->configuration))->bindOptional(\Loom\Monitoring\MonitoringOptions::class, 'monitoring');
        if ($monitoringOptions instanceof \Loom\Monitoring\MonitoringOptions) {
            $monitoringOptions->finalize();
            \Loom\Monitoring\MonitoringDashboard::register($router, $monitoringOptions->dashboard->path, $monitoringOptions);
        }
        $kernel = new HttpKernel($router);
        $console = new Console\Console();
        $grpc = new Grpc\Dispatcher($this->configuration);
        $services->addInstance(ModuleDiscovery::class, $discovery);
        $services->addInstance(Router::class, $router);
        $services->addInstance(HttpKernel::class, $kernel);
        $services->addInstance(Console\Console::class, $console);
        $services->addInstance(Grpc\Dispatcher::class, $grpc);
        $timers = $discovery->timers();
        $services->tryAddSingleton(TimerRunner::class, static function (ServiceResolver $resolver) use ($timers): TimerRunner {
            $runner = new TimerRunner($resolver->get(ServiceProvider::class));
            foreach ($timers as $class) {
                $runner->register($class);
            }
            return $runner;
        });
        $console->add(Console\Commands\DoctorCommand::class);
        $console->add(Console\Commands\ServeCommand::class);
        $console->add(Console\Commands\TcpServeCommand::class);
        $console->add(Console\Commands\GrpcServeCommand::class);
        $console->add(Console\Commands\MigrateUpCommand::class);
        $console->add(Console\Commands\MigrateDownCommand::class);
        $console->add(Console\Commands\MigrateStatusCommand::class);
        $console->add(Console\Commands\QueueWorkCommand::class);
        $console->add(Console\Commands\ScheduleListCommand::class);
        $console->add(Console\Commands\ScheduleRunCommand::class);
        $console->add(Console\Commands\ScheduleListenCommand::class);
        $console->add(Console\Commands\ScheduleWorkCommand::class);
        $console->add(Console\Commands\JobCommand::class);
        $console->add(Console\Commands\MonitoringPruneCommand::class);
        Console\Commands\ScaffoldCommands::register($console);
        foreach ($discovery->commands() as $class) {
            $console->add($class);
        }
        $provider = $services->build();
        if ($this->onStop !== null) {
            $provider->get(StopSignal::class)->onStop($this->onStop);
        }
        return new Application($provider, $this->configuration, $router, $kernel, $console, $grpc);
    }
}
