<?php
final class InvalidNativeInjectClosure {
    public function __construct() {
        $callback = function (#[\Container\Annotation\Inject('probe')] object $dependency): void {};
    }
}
