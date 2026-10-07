# End-to-end API smoke test. Usage: powershell -File bin/api-smoke-test.ps1 [-Base http://localhost/ffms/api] [-Password Farm@Demo2026]
param(
    [string]$Base = 'http://localhost/ffms/api',
    [string]$Password = 'Farm@Demo2026'
)
$ErrorActionPreference = 'Stop'
$script:pass = 0; $script:fail = 0

function Check([string]$name, [bool]$ok, [string]$detail = '') {
    if ($ok) { $script:pass++; Write-Host "  PASS  $name" -ForegroundColor Green }
    else { $script:fail++; Write-Host "  FAIL  $name $detail" -ForegroundColor Red }
}

function Call($sess, [string]$method, [string]$path, $body = $null, [string]$csrf = '') {
    $h = @{}
    if ($csrf) { $h['X-CSRF-Token'] = $csrf }
    $args = @{ Uri = "$Base/$path"; Method = $method; WebSession = $sess; Headers = $h; UseBasicParsing = $true; ContentType = 'application/json' }
    if ($null -ne $body) { $args['Body'] = ($body | ConvertTo-Json -Depth 8) }
    try {
        $r = Invoke-WebRequest @args
        return @{ status = [int]$r.StatusCode; json = ($r.Content | ConvertFrom-Json) }
    } catch {
        $resp = $_.Exception.Response
        $code = if ($resp) { [int]$resp.StatusCode } else { 0 }
        $json = $null
        if ($_.ErrorDetails -and $_.ErrorDetails.Message) { try { $json = $_.ErrorDetails.Message | ConvertFrom-Json } catch {} }
        return @{ status = $code; json = $json }
    }
}

function Login([string]$email) {
    $s = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $r = Call $s 'POST' 'auth/login' @{ email = $email; password = $Password }
    if ($r.status -ne 200) { throw "login failed for $email ($($r.status))" }
    return @{ s = $s; csrf = $r.json.data.csrf; user = $r.json.data.user }
}

Write-Host "`n== Authentication =="
$anon = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Check 'unauthenticated request is rejected (401)' ((Call $anon 'GET' 'fields').status -eq 401)
Check 'bad password is rejected (401)' ((Call $anon 'POST' 'auth/login' @{ email = 'admin@ffms.test'; password = 'wrong-pass-1' }).status -eq 401)
$admin = Login 'admin@ffms.test'
Check 'admin can log in' ($admin.user.role -eq 'admin')
Check 'mutation without CSRF token is rejected (403)' ((Call $admin.s 'POST' 'crops' @{ name = 'X' }).status -eq 403)

Write-Host "`n== Core data =="
$r = Call $admin.s 'GET' 'meta'
Check 'meta returns resources for admin' ($r.status -eq 200 -and @($r.json.data.resources.PSObject.Properties).Count -gt 40)
$r = Call $admin.s 'GET' 'fields?farm_id=1'
Check 'fields list (farm 1)' ($r.status -eq 200 -and $r.json.data.Count -eq 6) "got $($r.json.data.Count)"
$r = Call $admin.s 'GET' 'dashboard?farm_id=1'
Check 'dashboard loads with KPIs' ($r.status -eq 200 -and $r.json.data.land.fields -eq 6 -and $r.json.data.finance.income_ytd -gt 0)
$r = Call $admin.s 'GET' 'weather?farm_id=1'
Check 'weather overview has forecast' ($r.status -eq 200 -and $r.json.data.forecast.Count -ge 3)
$r = Call $admin.s 'GET' 'predictions/yield?farm_id=1'
Check 'yield predictions for active plantings' ($r.status -eq 200 -and $r.json.data.Count -ge 5 -and $r.json.data[0].factors.Count -ge 5)
$r = Call $admin.s 'GET' 'predictions/price/1'
Check 'price forecast for a crop' ($r.status -eq 200 -and $r.json.data.available -eq $true -and $r.json.data.forecast.Count -eq 6)
$r = Call $admin.s 'GET' 'predictions/recommendations?farm_id=1'
Check 'weather-based recommendations' ($r.status -eq 200 -and $r.json.data.crop_suitability.Count -ge 3)
foreach ($rep in 'crop_production','yield','livestock','financial','expenses','sales','inventory','labour','irrigation','pest_disease','equipment','profitability','farm_performance') {
    $r = Call $admin.s 'GET' "reports/${rep}?farm_id=1&from=2025-01-01"
    Check "report: $rep" ($r.status -eq 200 -and $r.json.data.columns.Count -gt 0) "status $($r.status) $($r.json.message)"
}

Write-Host "`n== Validation & integrity =="
$r = Call $admin.s 'POST' 'crops' @{ farm_id = 1; name = ''; days_to_maturity = -5; expected_yield_kg_ha = 'abc' } $admin.csrf
Check 'invalid crop rejected with field errors (422)' (($r.status -eq 422) -and ($null -ne $r.json.errors.name))
$r = Call $admin.s 'POST' 'stock_movements' @{ item_id = 1; movement_type = 'out'; quantity = 99999999; movement_date = (Get-Date).ToString('yyyy-MM-dd') } $admin.csrf
Check 'stock-out beyond available is blocked (409)' ($r.status -eq 409) "status $($r.status)"
$r = Call $admin.s 'DELETE' 'crops/1' $null $admin.csrf
Check 'deleting a crop in use is blocked (409)' ($r.status -eq 409) "status $($r.status)"

Write-Host "`n== Business workflows =="
$today = (Get-Date).ToString('yyyy-MM-dd')
$before = (Call $admin.s 'GET' 'inventory_items/1').json.data.quantity
$r = Call $admin.s 'POST' 'stock_movements' @{ item_id = 1; movement_type = 'out'; quantity = 10; movement_date = $today; reference = 'smoke test' } $admin.csrf
$after = (Call $admin.s 'GET' 'inventory_items/1').json.data.quantity
Check 'stock movement adjusts inventory quantity' ($r.status -eq 201 -and ($before - $after) -eq 10) "before=$before after=$after"

$r = Call $admin.s 'POST' 'sales_orders' @{ farm_id = 1; customer_id = 1; order_date = $today; items = @(@{ crop_id = 1; quantity_kg = 1000; unit_price = 6.5 }, @{ crop_id = 2; quantity_kg = 500; unit_price = 12 }) } $admin.csrf
Check 'sales order with lines created; total computed' ($r.status -eq 201 -and [double]$r.json.data.total_amount -eq 12500) "status $($r.status) total=$($r.json.data.total_amount) $($r.json.message)"
$oid = $r.json.data.id
$r = Call $admin.s 'POST' "sales/orders/$oid/invoice" $null $admin.csrf
Check 'invoice generated from order' ($r.status -eq 201) "status $($r.status)"
$iid = $r.json.data.id
$r = Call $admin.s 'POST' 'payments' @{ invoice_id = $iid; payment_date = $today; amount = 99999999; method = 'bank' } $admin.csrf
Check 'overpayment rejected (422)' ($r.status -eq 422)
$r = Call $admin.s 'POST' 'payments' @{ invoice_id = $iid; payment_date = $today; amount = 5000; method = 'bank' } $admin.csrf
Check 'partial payment accepted' ($r.status -eq 201)
$inv = (Call $admin.s 'GET' "invoices/$iid").json.data
Check 'invoice becomes partial with balance 7500' ($inv.status -eq 'partial' -and [double]$inv.balance -eq 7500) "status=$($inv.status) balance=$($inv.balance)"
$tx = (Call $admin.s 'GET' 'transactions?farm_id=1&txn_type=income&per_page=5').json.data
Check 'payment auto-posted to finance ledger' ($tx[0].amount -eq 5000 -and $tx[0].ref_type -eq 'payment')
Check 'auto-posted ledger rows are protected from edit (403)' ((Call $admin.s 'PATCH' "transactions/$($tx[0].id)" @{ amount = 1 } $admin.csrf).status -eq 403)

$r = Call $admin.s 'POST' 'purchase_orders' @{ farm_id = 1; supplier_id = 2; order_date = $today; items = @(@{ item_id = 7; description = 'Urea 46%'; quantity = 10; unit_price = 650 }) } $admin.csrf
Check 'purchase order created in draft' ($r.status -eq 201 -and $r.json.data.status -eq 'draft')
$poid = $r.json.data.id
$urea = (Call $admin.s 'GET' 'inventory_items/7').json.data.quantity
foreach ($step in 'submit','approve','order','receive') { $x = Call $admin.s 'POST' "purchasing/$poid/$step" $null $admin.csrf; if ($x.status -ne 200) { Check "PO step $step" $false "status $($x.status) $($x.json.message)" } }
$ureaAfter = (Call $admin.s 'GET' 'inventory_items/7').json.data.quantity
Check 'receiving a PO adds stock' (($ureaAfter - $urea) -eq 10) "before=$urea after=$ureaAfter"
Check 'PO cannot skip workflow steps (409)' ((Call $admin.s 'POST' "purchasing/$poid/approve" $null $admin.csrf).status -eq 409)

$r = Call $admin.s 'POST' 'harvests' @{ planting_id = 5; harvest_date = $today; quantity_kg = 2000; grade = 'A'; loss_kg = 100; warehouse_id = 1; mark_complete = $false } $admin.csrf
Check 'harvest recorded' ($r.status -eq 201)
$hid = [int]$r.json.data.id
$lots = @((Call $admin.s 'GET' 'storage_lots?farm_id=1&per_page=500').json.data)
Check 'storage lot auto-created for the harvest' (@($lots | Where-Object { [int]$_.harvest_id -eq $hid }).Count -eq 1)

$r = Call $admin.s 'GET' 'alerts?farm_id=1&per_page=300'
Check 'alert engine produced alerts' ($r.status -eq 200 -and @($r.json.data).Count -ge 8)

$r = Call $admin.s 'POST' 'payroll/generate' @{ farm_id = 1; period_start = (Get-Date).ToString('yyyy-MM-01'); period_end = (Get-Date).ToString('yyyy-MM-dd') } $admin.csrf
Check 'payroll generates drafts from attendance' ($r.status -eq 201 -and $r.json.data.created -ge 1) "status $($r.status) $($r.json.message)"

Write-Host "`n== Role-based access =="
$worker = Login 'worker@ffms.test'
Check 'worker cannot read transactions (403)' ((Call $worker.s 'GET' 'transactions').status -eq 403)
$wp = @((Call $worker.s 'GET' 'payroll_runs').json.data)
Check 'worker sees only their own payroll' ($wp.Count -gt 0 -and @($wp | Where-Object { $_.worker_name -ne 'Joseph Phiri' }).Count -eq 0)
$wt = @((Call $worker.s 'GET' 'tasks').json.data)
Check 'worker sees only own tasks' ($wt.Count -gt 0 -and @($wt | Where-Object { $_.worker_name -ne 'Joseph Phiri' }).Count -eq 0)
Check 'worker cannot read invoices (403)' ((Call $worker.s 'GET' 'invoices').status -eq 403)
Check 'worker cannot read sales orders (403)' ((Call $worker.s 'GET' 'sales_orders').status -eq 403)
Check 'worker cannot use options of restricted data (403)' ((Call $worker.s 'GET' 'options/invoices').status -eq 403)
Check 'worker cannot see price forecasts (403)' ((Call $worker.s 'GET' 'predictions/price/1').status -eq 403)
$winv = @((Call $worker.s 'GET' 'inventory_items').json.data)
Check 'worker inventory hides unit cost and value' ($winv.Count -gt 0 -and $null -eq $winv[0].unit_cost -and $null -eq $winv[0].stock_value)
$walerts = @((Call $worker.s 'GET' 'alerts?per_page=300').json.data)
Check 'worker alerts exclude market-price alerts' ($walerts.Count -gt 0 -and @($walerts | Where-Object { $_.alert_type -eq 'market_opportunity' }).Count -eq 0)
Check 'worker cannot manage users (403)' ((Call $worker.s 'GET' 'users').status -eq 403)
Check 'worker cannot create crops (403)' ((Call $worker.s 'POST' 'crops' @{ name = 'X'; days_to_maturity = 10; expected_yield_kg_ha = 1 } $worker.csrf).status -eq 403)
Check 'worker cannot open financial report (403)' ((Call $worker.s 'GET' 'reports/financial').status -eq 403)
$acct = Login 'accountant@ffms.test'
Check 'accountant can read transactions' ((Call $acct.s 'GET' 'transactions').status -eq 200)
Check 'accountant cannot create fields (403)' ((Call $acct.s 'POST' 'fields' @{ farm_id = 1; name = 'Z'; size_ha = 1 } $acct.csrf).status -eq 403)
$mgr2 = Login 'manager2@ffms.test'
$mf = (Call $mgr2.s 'GET' 'fields?farm_id=1').json.data
Check 'farm-scoped manager cannot see another farm (data isolation)' (($mf | Where-Object { $_.farm_id -ne 2 }).Count -eq 0 -and $mf.Count -eq 1) "got $($mf.Count)"
Check 'farm-scoped manager cannot fetch other farm record (404)' ((Call $mgr2.s 'GET' 'fields/1').status -eq 404)
$agro = Login 'agronomist@ffms.test'
Check 'agronomist can record scouting' ((Call $agro.s 'POST' 'scouting_reports' @{ field_id = 3; report_date = $today; severity = 'low'; affected_area_pct = 2 } $agro.csrf).status -eq 201)
Check 'agronomist cannot read invoices (403)' ((Call $agro.s 'GET' 'invoices').status -eq 403)
Check 'agronomist cannot read purchase orders (403)' ((Call $agro.s 'GET' 'purchase_orders').status -eq 403)
Check 'manager can read invoices' ((Call (Login 'manager@ffms.test').s 'GET' 'invoices').status -eq 200)

Write-Host "`n== Admin =="
$r = Call $admin.s 'GET' 'users'
Check 'admin lists users without password hashes' ($r.status -eq 200 -and -not ($r.json.data | Where-Object { $_.password_hash }))
$r = Call $admin.s 'POST' 'users' @{ name = 'Test User'; email = 'smoke@ffms.test'; role = 'worker'; password = 'weak' } $admin.csrf
Check 'weak password rejected (422)' ($r.status -eq 422)
$r = Call $admin.s 'POST' 'backups' $null $admin.csrf
Check 'admin can create a database backup' ($r.status -eq 201 -and $r.json.data.size -gt 10000)
$r = Call $admin.s 'GET' 'audit_logs?per_page=5'
Check 'audit log records activity' ($r.status -eq 200 -and $r.json.data.Count -gt 0)
Check 'owner cannot read backups (403)' ((Call (Login 'owner@ffms.test').s 'GET' 'backups').status -eq 403)

Write-Host "`nResult: $script:pass passed, $script:fail failed"
if ($script:fail -gt 0) { exit 1 }
