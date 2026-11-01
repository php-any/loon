param([string]$Origami = '', [string]$DatabaseDsn = $env:LOOM_DATABASE)
if (!$DatabaseDsn -or !$DatabaseDsn.StartsWith('mysql:')) { throw 'A disposable MySQL DatabaseDsn is required' }
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
. (Join-Path $root 'scripts/runtime.ps1')
$runtime = Resolve-LoomRuntime $Origami
$tag = [guid]::NewGuid().ToString('N')
$stdout = Join-Path $root "storage/testing/admin-$tag.out.log"
$stderr = Join-Path $root "storage/testing/admin-$tag.err.log"
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start(); $port = $listener.LocalEndpoint.Port; $listener.Stop()
$origin = "http://127.0.0.1:$port"
$oldDatabase = $env:LOOM_DATABASE; $oldAddress = $env:LOOM_ADDRESS
$server = $null
function Check($value, [string]$message) { if (!$value) { throw $message } }
function Call-Api([string]$path, $session, $csrf = '', $body = $null, [string]$method = '') {
    if (!$method) { $method = if ($null -eq $body) { 'GET' } else { 'POST' } }
    $params = @{ Uri="$origin$path"; Method=$method; WebSession=$session; Headers=@{Origin=$origin; 'X-CSRF-Token'=$csrf}; SkipHttpErrorCheck=$true; TimeoutSec=10 }
    if ($null -ne $body) { $params.ContentType='application/json'; $params.Body=($body | ConvertTo-Json -Depth 10 -Compress) }
    return Invoke-WebRequest @params
}
try {
    New-Item -ItemType Directory -Force -Path (Join-Path $root 'storage/testing') | Out-Null
    $env:LOOM_DATABASE=$DatabaseDsn; $env:LOOM_ADDRESS="127.0.0.1:$port"
    $server=Start-Process -FilePath $runtime -ArgumentList @('loom.php','serve') -WorkingDirectory $root -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    $ready=$false
    for($i=0;$i -lt 60;$i++) { if($server.HasExited){throw (Get-Content $stderr -Raw)}; try { Invoke-WebRequest "$origin/admin" -TimeoutSec 2 | Out-Null; $ready=$true;break } catch { Start-Sleep -Milliseconds 100 } }
    Check $ready 'Admin server readiness'
    $anon=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    Check ((Call-Api '/api/admin/session' $anon).StatusCode -eq 401) 'Anonymous session denied'
    $entry = Call-Api '/admin' $anon
    Check ($entry.Content -match '<div id="app"></div>') 'Standalone SPA entry served'
    $assets = [regex]::Matches($entry.Content, '(?:src|href)="(/assets/admin/[^" ]+\.(?:js|css))"')
    Check ($assets.Count -ge 2) 'Built module and stylesheet linked'
    foreach ($asset in $assets) {
        Check ((Call-Api $asset.Groups[1].Value $anon).StatusCode -eq 200) ('Built asset served: ' + $asset.Groups[1].Value)
    }
    $rootSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $memberSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $previousInitialPassword=$env:LOOM_ADMIN_INITIAL_PASSWORD
    try {
        $env:LOOM_ADMIN_INITIAL_PASSWORD='safe password 123'
        & $runtime loom.php admin:create root@example.test Administrator
        Check ($LASTEXITCODE -eq 0) 'Create administrator from console'
        $env:LOOM_ADMIN_INITIAL_PASSWORD='safe password 456'
        & $runtime loom.php admin:create member@example.test Member
        Check ($LASTEXITCODE -eq 0) 'Create limited administrator from console'
    } finally { $env:LOOM_ADMIN_INITIAL_PASSWORD=$previousInitialPassword }
    Check ((Call-Api '/api/studio/register' $anon '' @{name='Blocked';email='public-admin@example.test';password='safe password 123'}).StatusCode -eq 403) 'Public administrator registration denied'
    $rootRegistration=Call-Api '/api/studio/login' $rootSession '' @{email='root@example.test';password='safe password 123'}
    Check ($rootRegistration.StatusCode -eq 200) 'Root registration'
    $rootCsrf=($rootRegistration.Content | ConvertFrom-Json).csrf
    $memberRegistration=Call-Api '/api/studio/login' $memberSession '' @{email='member@example.test';password='safe password 456'}
    $memberCsrf=($memberRegistration.Content | ConvertFrom-Json).csrf
    Check ((Call-Api '/api/admin/manage/users' $rootSession).StatusCode -eq 403) 'First account not automatically privileged'
    & $runtime loom.php admin:bootstrap root@example.test
    Check ($LASTEXITCODE -eq 0) 'Bootstrap command'
    $state=(Call-Api '/api/admin/session' $rootSession).Content | ConvertFrom-Json
    Check ($state.permissions -contains '*') 'Root capabilities live'
    foreach($resource in @('orders','queue','schedules','notifications','monitor','channels')) {
        $response=Call-Api "/api/admin/operations/$resource" $rootSession
        Check ($response.StatusCode -eq 200) ("Operations $resource available: "+$response.Content)
        Check ((Call-Api "/api/admin/operations/$resource" $memberSession).StatusCode -eq 403) ("Operations $resource permission")
    }
    $orderBody=@{action='create';user_id=2;request_key=[guid]::NewGuid().ToString();items=@(@{name='HTTP service';quantity=2;unit_price=1200})}
    Check ((Call-Api '/api/admin/operations/orders' $rootSession '' $orderBody).StatusCode -eq 403) 'Order CSRF guard'
    $created=Call-Api '/api/admin/operations/orders' $rootSession $rootCsrf $orderBody
    Check ($created.StatusCode -eq 200) ('Create order: '+$created.Content)
    $orderId=($created.Content | ConvertFrom-Json).id
    Check ((Call-Api '/api/admin/operations/orders' $rootSession $rootCsrf $orderBody).StatusCode -eq 409) 'Duplicate order guarded'
    $detail=(Call-Api "/api/admin/operations/orders?id=$orderId" $rootSession).Content | ConvertFrom-Json
    Check ($detail.items[0].total -eq 2400 -and $detail.details.Count -eq 1) 'Order cents calculated'
    $transition=@{action='transition';id=$orderId;expected='pending';status='processing'}
    Check ((Call-Api '/api/admin/operations/orders' $rootSession $rootCsrf $transition).StatusCode -eq 200) 'Order transition'
    Check ((Call-Api '/api/admin/operations/orders' $rootSession $rootCsrf $transition).StatusCode -eq 409) 'Stale transition guarded'
    $inbox=(Call-Api '/api/admin/operations/inbox' $memberSession).Content | ConvertFrom-Json
    Check ($inbox.unread -eq 1) 'Order notification in member inbox'
    Check ((Call-Api '/api/admin/operations/inbox' $rootSession $rootCsrf @{action='read';id=[int]$inbox.items[0].id}).StatusCode -eq 404) 'Inbox ownership enforced for root'
    Check ((Call-Api '/api/admin/operations/inbox' $memberSession $memberCsrf @{action='read';id=[int]$inbox.items[0].id}).StatusCode -eq 200) 'Read own notification'
    $customerSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $customer=Call-Api '/api/customer/register' $customerSession '' @{name='Customer';email='root@example.test';password='customer password 123'}
    Check ($customer.StatusCode -eq 201) ('Independent customer registration: '+$customer.Content)
    $customerCsrf=($customer.Content | ConvertFrom-Json).csrf
    Check ((Call-Api '/api/admin/session' $customerSession).StatusCode -eq 401) 'Customer cookie cannot access administrator session'
    Check ((Call-Api '/api/studio/login' $anon '' @{email='root@example.test';password='customer password 123'}).StatusCode -eq 401) 'Customer credentials cannot log in as administrator'
    Check ((Call-Api '/api/customer/session' $rootSession).StatusCode -eq 401) 'Administrator cookie cannot access customer session'
    $coexist=Call-Api '/api/customer/login' $rootSession '' @{email='root@example.test';password='customer password 123'}
    Check ($coexist.StatusCode -eq 200) 'Both identities can sign in in one browser'
    Check ((Call-Api '/api/admin/session' $rootSession).StatusCode -eq 200) 'Customer login preserves administrator session'
    $coexistCsrf=($coexist.Content | ConvertFrom-Json).csrf
    Check ((Call-Api '/api/customer/logout' $rootSession $coexistCsrf @{}).StatusCode -eq 200) 'Customer logout'
    Check ((Call-Api '/api/admin/session' $rootSession).StatusCode -eq 200) 'Customer logout preserves administrator session'
    $paymentBody=@{request_key=[guid]::NewGuid().ToString()}
    Check ((Call-Api '/api/checkout/simulate' $rootSession $rootCsrf $paymentBody).StatusCode -eq 401) 'Administrator cannot pay as customer'
    Check ((Call-Api '/api/checkout/simulate' $anon '' $paymentBody).StatusCode -eq 401) 'Anonymous payment denied'
    Check ((Call-Api '/api/checkout/simulate' $customerSession '' $paymentBody).StatusCode -eq 403) 'Payment requires customer CSRF'
    Check ((Call-Api '/api/checkout/simulate' $customerSession $rootCsrf $paymentBody).StatusCode -eq 403) 'Administrator CSRF cannot authorize customer payment'
    Check ((Call-Api '/api/checkout/simulate' $customerSession $customerCsrf @{request_key=$paymentBody.request_key;total=1}).StatusCode -eq 422) 'Client cannot override payment amount'
    $payment=Call-Api '/api/checkout/simulate' $customerSession $customerCsrf $paymentBody
    Check ($payment.StatusCode -eq 200) ('Simulated payment: '+$payment.Content)
    $paid=($payment.Content | ConvertFrom-Json).order
    Check ($paid.total -eq 9900 -and $paid.status -eq 'completed') 'Payment amount and completion'
    $again=Call-Api '/api/checkout/simulate' $customerSession $customerCsrf $paymentBody
    Check (($again.Content | ConvertFrom-Json).order.id -eq $paid.id) 'Payment retry is idempotent'
    $rootInbox=(Call-Api '/api/admin/operations/inbox' $rootSession).Content | ConvertFrom-Json
    Check (@($rootInbox.items | Where-Object title -eq '用户模拟支付成功').Count -eq 1) 'Administrator receives one customer payment notification'
    $customerOrders=(Call-Api '/api/customer/orders' $customerSession).Content | ConvertFrom-Json
    Check ($customerOrders.items.Count -eq 1) 'Customer sees own purchase without duplicate'
    $purchases=(Call-Api '/api/admin/purchases' $rootSession).Content | ConvertFrom-Json
    Check ($purchases.total -eq 1 -and $purchases.items[0].email -eq 'root@example.test') 'Administrator sees customer purchase'
    Check ((Call-Api '/api/admin/customers' $customerSession).StatusCode -eq 401) 'Customers cannot manage customers'
    Check ((Call-Api '/api/admin/customers' $memberSession).StatusCode -eq 403) 'Limited administrators need customer management permission'
    $customers=(Call-Api '/api/admin/customers' $rootSession).Content | ConvertFrom-Json
    Check ($customers.total -eq 1) 'Customer and administrator lists are separate'
    $newCustomer=Call-Api '/api/admin/customers' $rootSession $rootCsrf @{id=0;name='Second customer';email='second@example.test';password='second customer password';enabled=$true}
    Check ($newCustomer.StatusCode -eq 200) ('Administrator creates customer: '+$newCustomer.Content)
    $secondSession=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    Check ((Call-Api '/api/customer/login' $secondSession '' @{email='second@example.test';password='second customer password'}).StatusCode -eq 200) 'Created customer can log in'
    Check ((Call-Api '/api/customer/orders' $secondSession).Content | ConvertFrom-Json).items.Count.Equals(0) 'Customers cannot see another customer orders'
    $customerId=[int]$customers.items[0].id
    Check ((Call-Api '/api/admin/customers' $rootSession $rootCsrf @{id=$customerId;name='Customer';enabled=$false}).StatusCode -eq 200) 'Disable customer'
    Check ((Call-Api '/api/customer/session' $customerSession).StatusCode -eq 401) 'Disabling customer revokes customer session'
    Check ((Call-Api '/api/admin/session' $rootSession).StatusCode -eq 200) 'Same numeric ID administrator remains enabled'
    $homepageResponse=Call-Api '/' $anon
    Check ($homepageResponse.Content.Contains('/account?mode=register') -and !$homepageResponse.Content.Contains('id="official-account"')) 'Homepage links to independent customer center'
    Check ((Call-Api '/account' $anon).Content.Contains('id="official-account"')) 'Customer center exposes login and registration'
    $scheduleRows=(Call-Api '/api/admin/operations/schedules?q=Heartbeat' $rootSession).Content | ConvertFrom-Json
    Check ($scheduleRows.total -eq 1 -and $scheduleRows.items[0].seconds -eq 5) 'List code-declared timer'
    foreach ($action in @('create','toggle','edit','delete','preview')) {
        Check ((Call-Api '/api/admin/operations/schedules' $rootSession $rootCsrf @{action=$action}).StatusCode -eq 405) 'Timer API is read-only'
    }
    foreach ($path in @('/api/studio/cron','/api/studio/schedule')) {
        Check ((Call-Api $path $rootSession $rootCsrf @{enabled=$true}).StatusCode -eq 404) 'Legacy schedule write route removed'
    }
    Check ((Call-Api '/api/admin/operations/notifications' $rootSession $rootCsrf @{action='send';user_id=2;title='HTTP notice';body='Real persisted notice';request_key=[guid]::NewGuid().ToString()}).StatusCode -eq 200) 'Send notification'
    $createdUser=Call-Api '/api/admin/manage/users' $rootSession $rootCsrf @{action='save';id=0;name='Created';email='created@example.test';password='a secure new password';roles='2';enabled=$true}
    Check ($createdUser.StatusCode -eq 200) ('Admin creates user: '+$createdUser.Content)
    Check ((Call-Api ('/api/admin/operations/users?id='+($createdUser.Content | ConvertFrom-Json).id) $rootSession).StatusCode -eq 200) 'User detail'
    Check ((Call-Api '/api/admin/manage/users' $memberSession).StatusCode -eq 403) 'Member cannot read users'
    Check ((Call-Api '/api/admin/manage/settings' $memberSession $memberCsrf @{action='save';site_name='Hijack';site_description='';registration_enabled=$true}).StatusCode -eq 403) 'Member cannot update settings'
    Check ((Call-Api '/api/admin/manage/roles' $rootSession '' @{action='save';id=0;name='Denied';permissions='overview.view'}).StatusCode -eq 403) 'CSRF required'
    $cross=Invoke-WebRequest "$origin/api/admin/manage/roles" -Method Post -WebSession $rootSession -Headers @{Origin='https://evil.example';'X-CSRF-Token'=$rootCsrf} -ContentType 'application/json' -Body '{"action":"delete","id":1}' -SkipHttpErrorCheck
    Check ($cross.StatusCode -eq 403) 'Origin required'
    $role=Call-Api '/api/admin/manage/roles' $rootSession $rootCsrf @{action='save';id=0;name='Auditor';permissions='overview.view,audit.view'}
    Check ($role.StatusCode -eq 200) ('Create role '+$role.Content)
    $roleId=($role.Content | ConvertFrom-Json).id
    Check ((Call-Api '/api/admin/manage/users' $rootSession $rootCsrf @{action='save';id=2;name='Auditor';enabled=$true;roles="$roleId"}).StatusCode -eq 200) 'Assign role'
    Check ((Call-Api '/api/admin/manage/audit' $memberSession).StatusCode -eq 200) 'Assigned permission takes effect'
    Check ((Call-Api '/api/studio/state' $memberSession).StatusCode -eq 403) 'Removed workspace permission enforced'
    $memberState=(Call-Api '/api/admin/session' $memberSession).Content | ConvertFrom-Json
    Check (@($memberState.menus | Where-Object permission -eq 'users.manage').Count -eq 0) 'Forbidden menus absent'
    Check ((Call-Api '/api/studio/logout' $memberSession $memberCsrf @{}).StatusCode -eq 200) 'Logout works without workspace permission'
    Check ((Call-Api '/api/admin/manage/users' $rootSession $rootCsrf @{action='save';id=1;name='Root';enabled=$false;roles='1'}).StatusCode -eq 409) 'Last enabled root protected'
    $menu=Call-Api '/api/admin/manage/menus' $rootSession $rootCsrf @{action='save';id=0;label='Help';path='/guide';permission='overview.view';parent_id=0;sort_order=99;enabled=$true}
    Check ($menu.StatusCode -eq 200) ('Create menu '+$menu.Content)
    $menuId=($menu.Content | ConvertFrom-Json).id
    Check ((Call-Api '/api/admin/manage/menus' $rootSession $rootCsrf @{action='save';id=$menuId;label='Help';path='/guide';permission='overview.view';parent_id=$menuId;sort_order=99;enabled=$true}).StatusCode -eq 422) 'Menu cycle rejected'
    Check ((Call-Api '/api/admin/manage/menus' $rootSession $rootCsrf @{action='delete';id=$menuId}).StatusCode -eq 200) 'Delete menu'
    Check ((Call-Api '/api/admin/manage/settings' $rootSession $rootCsrf @{action='save';site_name='Integration Admin';site_description='Verified';registration_enabled=$false}).StatusCode -eq 200) 'Settings save'
    Check (((Call-Api '/api/admin/session' $rootSession).Content | ConvertFrom-Json).branding.name -eq 'Integration Admin') 'Branding live'
    Check ((Call-Api '/api/customer/register' $anon '' @{name='Blocked';email='blocked@example.test';password='safe password 789'}).StatusCode -eq 403) 'Registration toggle enforced'
    Check ((Call-Api '/api/admin/manage/users' $rootSession $rootCsrf @{action='save';id=2;name='Disabled';enabled=$false;roles='2'}).StatusCode -eq 200) 'Disable user'
    Check ((Call-Api '/api/studio/login' $memberSession '' @{email='member@example.test';password='safe password 456'}).StatusCode -eq 403) 'Disabled login denied'
    Check ((Call-Api '/api/account/request-reset' $anon '' @{email='missing@example.test'}).StatusCode -eq 200) 'Recovery does not enumerate users'
    Check ((Call-Api '/api/account/reset' $anon '' @{token=('a'*64);password='replacement password'}).StatusCode -eq 422) 'Invalid recovery token rejected'
    $audit=(Call-Api '/api/admin/manage/audit' $rootSession).Content | ConvertFrom-Json
    Check (@($audit.items | Where-Object action -eq 'settings.save').Count -gt 0) 'Audit contains management operations'
    Check ((Call-Api '/api/admin/manage/audit' $rootSession $rootCsrf @{action='delete';id=1}).StatusCode -eq 404) 'Audit immutable over API'
    Check ((Get-Item $stderr).Length -eq 0) ('Server errors: '+(Get-Content $stderr -Raw))
    Write-Host 'Admin HTTP, RBAC, menus, configuration, audit and account guards: OK'
} finally {
    if($server -and !$server.HasExited) { Stop-Process -Id $server.Id; $server.WaitForExit() }
    $env:LOOM_DATABASE=$oldDatabase; $env:LOOM_ADDRESS=$oldAddress
    foreach($file in @($stdout,$stderr)) {
        if([IO.Path]::GetFullPath($file).StartsWith([IO.Path]::GetFullPath((Join-Path $root 'storage/testing'))+[IO.Path]::DirectorySeparatorChar)) { Remove-Item -LiteralPath $file -ErrorAction SilentlyContinue }
    }
}


