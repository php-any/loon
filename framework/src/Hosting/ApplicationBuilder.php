<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用的组合根，对应 ASP.NET Core 的 WebApplicationBuilder。构造时读取环境文件和 config/，并登记框架默认服务；
 * 应用在 build() 之前通过 $services 追加或覆盖登记。
 *
 * build() 时扫描 addModule() 登记的模块：带 Service 注解的类按注解的生命周期补登记（应用已登记的标识不覆盖），
 * 控制器路由进入路由表，ConsoleCommand 类进入命令表，Timer 类交给 TimerRunner。
 *
 * @example $builder = Application::createBuilder(__DIR__); $builder->addModule(\App\AppModule::class); $builder->services->addSingleton(Clock::class); $app = $builder->build();
 */
final class ApplicationBuilder
{
    public readonly ServiceCollection $services;
    public readonly Config $configuration;
    public readonly \Loom\MonitoringBuilder $monitoring;
    public readonly \Loom\Filesystem\FilesystemBuilder $filesystems;
    public readonly \Loom\Events\EventsBuilder $events;
    public readonly \Loom\CacheBuilder $cache;
    public readonly \Loom\Logging\DiaryBuilder $diary;
    public readonly \Loom\QueueBuilder $queue;
    public readonly \Loom\Mail\MailBuilder $mail;
    private \Loom\Events\EventsRegistration $eventsRegistration;
    private bool $built = false;
    /** @var list<\Closure> */
    private array $onStopHandlers = [];
    /** @var list<string> */
    private array $modules = [];

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
        $diaryRegistration = new \Loom\Logging\DiaryRegistration();
        $services->addInstance(\Loom\Logging\DiaryRegistration::class, $diaryRegistration);
        $this->diary = new \Loom\Logging\DiaryBuilder($diaryRegistration);
        $queueRegistration = new \Loom\QueueRegistration();
        $services->addInstance(\Loom\QueueRegistration::class, $queueRegistration);
        $this->queue = new \Loom\QueueBuilder($queueRegistration);
        $mailRegistration = new \Loom\Mail\MailRegistration();
        $services->addInstance(\Loom\Mail\MailRegistration::class, $mailRegistration);
        $this->mail = new \Loom\Mail\MailBuilder($mailRegistration);
        $services->tryAddScoped(\Loom\Queue::class);
        $services->tryAddScoped(\Loom\Events\EventListenJob::class);
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
            return \Loom\Logging\DiaryComposer::createWriter(
                $options,
                $resolver->get(\Loom\Logging\DiaryRegistration::class),
                $resolver->get(Config::class),
            );
        });
        $services->tryAddSingleton(\Loom\Logging\JournalFormatter::class, static function (ServiceResolver $resolver): \Loom\Logging\JournalFormatter {
            $options = $resolver->get(\Loom\Logging\DiaryOptions::class);
            return \Loom\Logging\DiaryComposer::formatter($options->formatter);
        });
        $services->tryAddSingleton(\Loom\Logging\Diary::class, static function (ServiceResolver $resolver): \Loom\Logging\Diary {
            $options = $resolver->get(\Loom\Logging\DiaryOptions::class);
            $sink = \Loom\Logging\DiaryComposer::createSink(
                $options,
                $resolver->get(\Loom\Logging\DiaryRegistration::class),
                $resolver->get(Config::class),
            );
            return new \Loom\Logging\Diary($sink, null, $options->level);
        });
        $services->tryAddSingleton(\Loom\RedisOptions::class, static function (ServiceResolver $resolver): \Loom\RedisOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\RedisOptions::class, 'redis');
            if (!($bound instanceof \Loom\RedisOptions)) {
                throw new \LogicException('RedisOptions binding must return RedisOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\QueueOptions::class, static function (ServiceResolver $resolver): \Loom\QueueOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\QueueOptions::class, 'queue');
            if (!($bound instanceof \Loom\QueueOptions)) {
                throw new \LogicException('QueueOptions binding must return QueueOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\RedisQueue::class, static function (ServiceResolver $resolver): \Loom\RedisQueue {
            $options = $resolver->get(\Loom\QueueOptions::class);
            $profile = $options->connectionProfile($options->default);
            $factory = $resolver->get(\Loom\QueueRegistration::class)->factory($profile['driver']);
            if ($factory !== null) {
                $instance = $factory($resolver);
                if (!$instance instanceof \Loom\RedisQueue) {
                    throw new \LogicException('Queue driver factory must return RedisQueue');
                }
                return $instance;
            }
            if ($profile['driver'] !== 'redis') {
                throw new \LogicException('Unsupported queue driver: ' . $profile['driver']);
            }
            return new \Loom\RedisQueue(
                $resolver->get(Config::class),
                (string)($profile['connection'] ?? ''),
                (string)($profile['name'] ?? ''),
            );
        });
        $services->tryAddSingleton(\Loom\Mail\MailOptions::class, static function (ServiceResolver $resolver): \Loom\Mail\MailOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\Mail\MailOptions::class, 'mail');
            if (!($bound instanceof \Loom\Mail\MailOptions)) {
                throw new \LogicException('MailOptions binding must return MailOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\Mail\Mailer::class);
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
        $cacheRegistration = new \Loom\CacheRegistration();
        $services->addInstance(\Loom\CacheRegistration::class, $cacheRegistration);
        $this->cache = new \Loom\CacheBuilder($services, $cacheRegistration);
        $services->tryAddSingleton(\Loom\CacheOptions::class, static function (ServiceResolver $resolver): \Loom\CacheOptions {
            $bound = $resolver->get(Options::class)->bindOptional(\Loom\CacheOptions::class, 'cache');
            if (!($bound instanceof \Loom\CacheOptions)) {
                throw new \LogicException('CacheOptions binding must return CacheOptions');
            }
            return $bound;
        });
        $services->tryAddSingleton(\Loom\Cache::class, static function (ServiceResolver $resolver): \Loom\Cache {
            return new \Loom\Cache(
                $resolver->get(\Loom\CacheOptions::class),
                $resolver->get(\Loom\CacheRegistration::class),
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
        $this->onStopHandlers[] = $handler(...);
        return $this;
    }

    /**
     * 登记一个带 Module 注解的启动模块类。重复登记同一类会去重。必须在 build() 之前调用。
     *
     * @example $builder->addModule(\App\AppModule::class);
     */
    public function addModule(string $class): self
    {
        if ($this->built) {
            throw new \LogicException('Modules cannot be added after the application has been built');
        }
        foreach ($this->modules as $entry) {
            if ($entry === $class) {
                return $this;
            }
        }
        $this->modules[] = $class;
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
        $discovery = ModuleDiscovery::configured($this->configuration, $this->modules);
        foreach ($discovery->eventListeners() as $event => $listeners) {
            foreach ($listeners as $row) {
                $class = $row[0];
                $method = $row[1];
                $queued = $row[2] ?? false;
                if ($queued) {
                    $this->eventsRegistration->discoverQueued($event, $class, $method);
                } else {
                    $this->eventsRegistration->discover($event, $class, $method);
                }
            }
        }
        $listenerMap = $this->eventsRegistration->listeners();
        self::assertQueueableEventListeners($listenerMap);
        $services->tryAddScoped(\Loom\Events\EventDispatcher::class, static function (ServiceResolver $resolver) use ($listenerMap): \Loom\Events\EventDispatcher {
            return new \Loom\Events\EventDispatcher($listenerMap, $resolver, $resolver->get(\Loom\Queue::class));
        });
        $builtinJobs = ['loom.event.listen' => [\Loom\Events\EventListenJob::class, 'handle']];
        $services->addSingleton(JobDispatcher::class, static function (ServiceResolver $resolver) use ($builtinJobs): JobDispatcher {
            return new JobDispatcher($resolver->get(ModuleDiscovery::class), $builtinJobs);
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
        \Loom\Redis::bindOptions($provider->get(\Loom\RedisOptions::class));
        $stopSignal = $provider->get(StopSignal::class);
        foreach ($this->onStopHandlers as $handler) {
            $stopSignal->onStop($handler);
        }
        return new Application($provider, $this->configuration, $router, $kernel, $console, $grpc);
    }

  /**
   * @param array<string, list<array{type: string, listener: string|callable, method?: string, queued?: bool}>> $listenerMap
   */
    private static function assertQueueableEventListeners(array $listenerMap): void
    {
        foreach ($listenerMap as $event => $entries) {
            foreach ($entries as $entry) {
                if (!($entry['queued'] ?? false)) {
                    continue;
                }
                $reflection = new \ReflectionClass($event);
                if (!$reflection->implementsInterface(\Loom\Events\QueueableEvent::class)) {
                    throw new \LogicException('Queued event listeners require QueueableEvent: ' . $event);
                }
            }
        }
    }
}
