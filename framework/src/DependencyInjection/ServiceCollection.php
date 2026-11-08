<?php

declare(strict_types=1);

namespace Loom;

/**
 * 应用启动阶段的服务登记表，对应 ASP.NET Core 的 IServiceCollection。build() 之后变为只读，并生成根 ServiceProvider。
 *
 * 同一标识可以登记多次：get() 取最后一条，getAll() 按顺序取全部。tryAdd* 只在标识还没有登记时生效，框架和模块注解用它提供默认值，
 * 应用自己的 add* 登记因此总能覆盖默认值。
 *
 * @example $services = new ServiceCollection(); $services->addScoped(Repository::class, SqlRepository::class); $provider = $services->build();
 */
final class ServiceCollection
{
    /** @var array<string, list<ServiceDescriptor>> */
    private array $descriptors = [];
    private bool $built = false;

    public function add(ServiceDescriptor $descriptor): self
    {
        $this->assertWritable();
        $this->descriptors[$descriptor->id][] = $descriptor;
        return $this;
    }

    public function tryAdd(ServiceDescriptor $descriptor): self
    {
        if (!$this->has($descriptor->id)) {
            $this->add($descriptor);
        }
        return $this;
    }

    /**
     * 登记单例。实现可以是类名、接收 ServiceResolver 的工厂，或 null（按 $id 构造）。
     */
    public function addSingleton(string $id, string|callable|null $implementation = null): self
    {
        return $this->add(ServiceDescriptor::singleton($id, $implementation));
    }

    public function addScoped(string $id, string|callable|null $implementation = null): self
    {
        return $this->add(ServiceDescriptor::scoped($id, $implementation));
    }

    public function addTransient(string $id, string|callable|null $implementation = null): self
    {
        return $this->add(ServiceDescriptor::transient($id, $implementation));
    }

    /**
     * 登记现成的对象，作为单例共用。容器不会释放它。
     */
    public function addInstance(string $id, object $instance): self
    {
        return $this->add(ServiceDescriptor::instance($id, $instance));
    }

    public function tryAddSingleton(string $id, string|callable|null $implementation = null): self
    {
        return $this->tryAdd(ServiceDescriptor::singleton($id, $implementation));
    }

    public function tryAddScoped(string $id, string|callable|null $implementation = null): self
    {
        return $this->tryAdd(ServiceDescriptor::scoped($id, $implementation));
    }

    public function tryAddTransient(string $id, string|callable|null $implementation = null): self
    {
        return $this->tryAdd(ServiceDescriptor::transient($id, $implementation));
    }

    /**
     * 把配置节绑定成单例选项对象，对应 services.Configure<T>(section)。第一次解析时读配置并执行校验；校验失败不缓存。
     *
     * @param string $class 选项类，构造函数不能有必填参数。
     * @param string $section 点分配置节，值必须是数组。
     * @param ?callable $validate 接收绑定好的对象，不合格时抛出异常。
     */
    public function configure(string $class, string $section, ?callable $validate = null): self
    {
        if ($class === '' || $section === '') {
            throw new \InvalidArgumentException('Options class and section must not be empty');
        }
        return $this->addSingleton($class, static function (ServiceResolver $services) use ($class, $section, $validate): object {
            $options = (new Options($services->get(Config::class)))->bind($class, $section);
            if ($validate !== null) {
                $validate($options);
            }
            return $options;
        });
    }

    /**
     * 登记后台服务，对应 services.AddHostedService<T>()。类必须实现 HostedService，作为单例由 Host 启动和停止。
     */
    public function addHostedService(string $class): self
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException('Hosted service class does not exist: ' . $class);
        }
        return $this->addSingleton(HostedService::class, $class);
    }

    public function has(string $id): bool
    {
        return isset($this->descriptors[$id]);
    }

    /**
     * 删除标识的全部登记，对应 RemoveAll。测试用它换掉默认实现。
     */
    public function remove(string $id): self
    {
        $this->assertWritable();
        unset($this->descriptors[$id]);
        return $this;
    }

    /**
     * @return list<ServiceDescriptor> 该标识的登记，按登记顺序。
     */
    public function descriptors(string $id): array
    {
        return $this->descriptors[$id] ?? [];
    }

    /**
     * 冻结登记表，生成根容器。只能调用一次。
     */
    public function build(): ServiceProvider
    {
        $this->assertWritable();
        $this->built = true;
        return new ServiceProvider($this->descriptors);
    }

    private function assertWritable(): void
    {
        if ($this->built) {
            throw new \LogicException('Services cannot be changed after the service provider has been built');
        }
    }
}
