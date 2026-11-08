<?php
use Loom\InjectConfig;
/**
 * 记录构造参数，验证配置注入、默认值、显式 null 与普通参数。
 *
 * 构造参数：
 * - $timeout（int）：从 probe.timeout 注入的整数配置值。
 * - $enabled（bool）：从 probe.enabled 注入的开关，缺失时使用属性声明的默认值。
 * - $nullable（?string）：允许为 null 的配置值，用于验证显式 null 不触发回退。
 * - $fallback（int）：缺失配置时注入的整数回退值。
 * - $optional（?string）：缺失配置允许注入的 null 值。
 * - $ordinary（string）：未标注配置注入的普通构造参数。默认值为 'ordinary'。
 *
 * 返回值：使用 new 构造时得到 ConfigConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigConstructorProbe
{
    public array $received;
    #[InjectConfig('probe.name')]
    public string $property;
    /**
     * 初始化 ConfigConstructorProbe 实例及其依赖。记录构造参数，验证配置注入、默认值、显式 null 与普通参数。
     *
     * @param int $timeout 从 probe.timeout 注入的整数配置值。
     * @param bool $enabled 从 probe.enabled 注入的开关，缺失时使用属性声明的默认值。
     * @param ?string $nullable 允许为 null 的配置值，用于验证显式 null 不触发回退。
     * @param int $fallback 缺失配置时注入的整数回退值。
     * @param ?string $optional 缺失配置允许注入的 null 值。
     * @param string $ordinary 未标注配置注入的普通构造参数。默认值为 'ordinary'。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(
        #[InjectConfig('probe.timeout')] int $timeout,
        #[InjectConfig('probe.enabled', true)] bool $enabled,
        #[InjectConfig('probe.nullable', 'fallback')] ?string $nullable,
        #[InjectConfig('probe.missing', 17)] int $fallback,
        #[InjectConfig(key: 'probe.absent', default: null)] ?string $optional,
        string $ordinary = 'ordinary'
    ) {
        $this->received = [$timeout, $enabled, $nullable, $fallback, $optional, $ordinary];
    }
}
/**
 * 继承配置注入构造函数，验证父类参数属性仍然生效。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 ConfigInheritedConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigInheritedConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigInheritedConstructorProbe extends ConfigConstructorProbe {}
/**
 * 故意把数组配置注入整数参数，验证类型错误报告。
 *
 * 构造参数：
 * - $value（int）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 ConfigInvalidConstructorTypeProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigInvalidConstructorTypeProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigInvalidConstructorTypeProbe { 
    /**
     * 初始化 ConfigInvalidConstructorTypeProbe 实例及其依赖。故意把数组配置注入整数参数，验证类型错误报告。
     *
     * @param int $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigInvalidConstructorTypeProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[InjectConfig('probe')] int $value) {} }
/**
 * 构造时主动抛出异常，验证容器保留构造失败原因。
 *
 * 构造参数：
 * - $timeout（int）：从 probe.timeout 注入的整数配置值。
 *
 * 返回值：使用 new 构造时得到 ConfigThrowingConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigThrowingConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigThrowingConstructorProbe { 
    /**
     * 初始化 ConfigThrowingConstructorProbe 实例及其依赖。构造时主动抛出异常，验证容器保留构造失败原因。
     *
     * @param int $timeout 从 probe.timeout 注入的整数配置值。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigThrowingConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[InjectConfig('probe.timeout')] int $timeout) { throw new RuntimeException('constructor failure'); } }
/**
 * 请求缺失的必填配置，验证缺失配置异常。
 *
 * 构造参数：
 * - $value（string）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 ConfigMissingConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigMissingConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigMissingConstructorProbe { public string $value; 
    /**
     * 初始化 ConfigMissingConstructorProbe 实例及其依赖。请求缺失的必填配置，验证缺失配置异常。
     *
     * @param string $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigMissingConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[InjectConfig('probe.required')] string $value) { $this->value = $value; } }
/**
 * 声明无法自动解析的必填标量，验证容器拒绝未提供参数。
 *
 * 构造参数：
 * - $required（string）：必须显式提供的标量，用于验证无法自动解析的参数。
 *
 * 返回值：使用 new 构造时得到 ConfigUnresolvedConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigUnresolvedConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigUnresolvedConstructorProbe { 
    /**
     * 初始化 ConfigUnresolvedConstructorProbe 实例及其依赖。声明无法自动解析的必填标量，验证容器拒绝未提供参数。
     *
     * @param string $required 必须显式提供的标量，用于验证无法自动解析的参数。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigUnresolvedConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(string $required) {} }
/**
 * 重复声明参数配置注入属性，验证重复属性被拒绝。
 *
 * 构造参数：
 * - $value（int）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 ConfigDuplicateConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigDuplicateConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigDuplicateConstructorProbe { 
    /**
     * 初始化 ConfigDuplicateConstructorProbe 实例及其依赖。重复声明参数配置注入属性，验证重复属性被拒绝。
     *
     * @param int $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigDuplicateConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[InjectConfig('probe.timeout')] #[InjectConfig('probe.zero')] int $value) {} }
/**
 * 记录默认字符串及可变整数参数，验证普通构造参数处理。
 *
 * 构造参数：
 * - $label（string）：测试记录的标签字符串。默认值为 'default'。
 * - $values（int）：按顺序传入的整数列表，用于验证可变参数。支持传入多个值。
 *
 * 返回值：使用 new 构造时得到 ConfigOptionalConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigOptionalConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigOptionalConstructorProbe { public array $values; 
    /**
     * 初始化 ConfigOptionalConstructorProbe 实例及其依赖。记录默认字符串及可变整数参数，验证普通构造参数处理。
     *
     * @param string $label 测试记录的标签字符串。默认值为 'default'。
     * @param int ...$values 按顺序传入的整数列表，用于验证可变参数。支持传入多个值。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigOptionalConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(string $label = 'default', int ...$values) { $this->values = [$label, $values]; } }
/**
 * 记录注入值，验证自定义工厂下的构造参数解析。
 *
 * 构造参数：
 * - $value（int）：夹具保存或使用的测试值；具体注入来源由参数属性声明。
 *
 * 返回值：使用 new 构造时得到 ConfigFactoryConstructorProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigFactoryConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigFactoryConstructorProbe { public int $value; 
    /**
     * 初始化 ConfigFactoryConstructorProbe 实例及其依赖。记录注入值，验证自定义工厂下的构造参数解析。
     *
     * @param int $value 夹具保存或使用的测试值；具体注入来源由参数属性声明。
     * @return void 无直接返回值；通过 new 表达式取得初始化后的实例。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $instance = $services->get(ConfigFactoryConstructorProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
     */
    public function __construct(#[InjectConfig('probe.timeout')] int $value) { $this->value = $value; } }
/**
 * 声明父类私有配置属性，验证继承层级中的属性注入。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConfigParentProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigParentProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigParentProbe
{
    #[InjectConfig('probe.name')]
    private string $name;
    #[InjectConfig('probe.enabled')]
    protected bool $enabled;
    /**
     * 读取父类私有的已注入配置值。
     *
     * 参数：无。
     *
     * @return string 父类名称配置字符串。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $probe->parentName();
     */
    public function parentName(): string { return $this->name; }
}
/**
 * 声明子类同名和布尔属性，验证父子私有配置分别注入。
 *
 * 构造参数：本声明未定义构造参数；继承类型沿用父类的构造约定。
 *
 * 返回值：使用 new 构造时得到 ConfigServiceProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigServiceProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigServiceProbe extends ConfigParentProbe
{
    #[InjectConfig('probe.timeout')]
    private int $name;
    #[InjectConfig('probe.timeout')]
    public int $timeout;
    #[InjectConfig('probe')]
    public array $settings;
    #[InjectConfig('probe.missing', 12)]
    public int $fallback;
    #[InjectConfig(key: 'probe.absent', default: null)]
    public ?string $nullableDefault;
    #[InjectConfig('probe.nullable', 'unused')]
    public ?string $nullable;
    #[InjectConfig('probe.zero', 99)]
    public int $zero;
    /**
     * 读取子类同名私有配置值。
     *
     * 参数：无。
     *
     * @return int 子类整数配置值。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $name = $probe->childName();
     */
    public function childName(): int { return $this->name; }
    /**
     * 读取已注入的布尔配置。
     *
     * 参数：无。
     *
     * @return bool 配置中的启用状态。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $enabled = $probe->enabled();
     */
    public function enabled(): bool { return $this->enabled; }
}
/**
 * 声明缺失的必填属性配置，验证初始化失败。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 MissingConfigProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(MissingConfigProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class MissingConfigProbe { #[InjectConfig('probe.required')] public string $value; }
/**
 * 故意将数组配置注入整数属性，验证属性类型检查。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 InvalidConfigTypeProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(InvalidConfigTypeProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class InvalidConfigTypeProbe { #[InjectConfig('probe')] public int $value; }
/**
 * 重复声明属性配置注入，验证重复属性错误。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 DuplicateConfigProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(DuplicateConfigProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class DuplicateConfigProbe { #[InjectConfig('probe.timeout')] #[InjectConfig('probe.zero')] public int $value; }
/**
 * 声明空配置键，验证无效配置键被拒绝。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 EmptyConfigKeyProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(EmptyConfigKeyProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class EmptyConfigKeyProbe { #[InjectConfig('')] public int $value; }
/**
 * 将注入的配置名称作为正文，验证控制器实例完成配置注入。
 *
 * 构造参数：无，本类型未声明构造参数。
 *
 * 返回值：使用 new 构造时得到 ConfigControllerProbe 实例；注册到容器时由容器管理实例的创建与复用。
 *
 * 使用示例（依赖对象由所在应用或测试环境提供）：
 * @example $instance = $services->get(ConfigControllerProbe::class); // 在对应测试环境中解析；无效夹具用于断言异常
 */
class ConfigControllerProbe
{
    #[InjectConfig('probe.name')]
    private string $name;
    /**
     * 把注入名称转为 HTTP 正文。
     *
     * @param \Loom\Request $request 当前 HTTP 请求；中间件修改属性时应传递请求副本。
     * @return \Loom\Response 正文为配置名称的响应。
     *
     * 使用示例（依赖对象由所在应用或测试环境提供）：
     * @example $response = $controller($request);
     */
    public function __invoke(\Loom\Request $request): \Loom\Response {
        return new \Loom\Response($this->name);
    }
}

