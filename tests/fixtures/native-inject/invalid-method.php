<?php
final class InvalidNativeInjectMethod {
    public function handle(#[\Container\Annotation\Inject('probe')] object $dependency): void {}
}
