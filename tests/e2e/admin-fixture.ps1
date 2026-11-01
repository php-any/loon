# 通过服务端控制台创建后台身份；公开注册仅面向客户。
function New-TestAdministrator([string]$Email, [string]$Name, [string]$Password) {
    $savedInitialPassword=$env:LOOM_ADMIN_INITIAL_PASSWORD
    try {
        $env:LOOM_ADMIN_INITIAL_PASSWORD=$Password
        & $runtime loom.php admin:create $Email $Name | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Unable to seed test administrator' }
    } finally { $env:LOOM_ADMIN_INITIAL_PASSWORD=$savedInitialPassword }
}
