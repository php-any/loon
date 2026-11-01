<?php
namespace NativeInjectRegression;

final class Dependency { public string $value = 'native'; }
final class Ordinary {
    public Dependency $dependency;
    public function __construct(#[\Container\Annotation\Inject('native.probe')] Dependency $dependency) { $this->dependency = $dependency; }
}
final class Promoted {
    public function __construct(#[\Container\Annotation\Inject('native.probe')] public Dependency $dependency) {}
}
class NamedParent {
    public Dependency $dependency;
    public function __construct(#[\Container\Annotation\Named('native.probe')] Dependency $dependency) { $this->dependency = $dependency; }
}
final class NamedChild extends NamedParent {}
final class Inferred {
    public Dependency $dependency;
    public function __construct(#[\Container\Annotation\Inject] Dependency $dependency) { $this->dependency = $dependency; }
}
