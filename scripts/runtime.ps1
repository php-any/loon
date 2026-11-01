# 通过点调用加载此文件，以解析项目宿主或显式指定的运行时。
function Resolve-LoomRuntime([string]$Runtime='') {
    if (!$Runtime) {
        $name=if ($IsWindows) { 'loom.exe' } else { 'loom' }
        $Runtime=Join-Path (Split-Path -Parent $PSScriptRoot) "build/$name"
    }
    $command=Get-Command $Runtime -ErrorAction SilentlyContinue
    if (!$command) { throw "Runtime not found: $Runtime. Run scripts/build.ps1 first." }
    return $command.Source
}
