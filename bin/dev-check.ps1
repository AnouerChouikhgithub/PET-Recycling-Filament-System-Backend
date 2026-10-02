# Dev sanity check (Windows PowerShell twin of bin/dev-check).
#
#   powershell -ExecutionPolicy Bypass -File bin/dev-check.ps1

Write-Host "== Docker postgres containers =="
$rows = @()
try { $rows = @(docker ps --format '{{.Names}} | {{.Image}} | {{.Ports}}' 2>$null) } catch {}
if (-not ($rows | Select-String -Pattern 'postgres' -Quiet)) {
    Write-Host "WARNING: no running postgres container. Start it: docker compose up -d"
}
$rows | Select-String -Pattern 'postgres|mailpit' | ForEach-Object { Write-Host $_ }
$images = @()
try { $images = @(docker ps --format '{{.Image}}' 2>$null) } catch {}
$count = ($images | Select-String -Pattern 'postgres').Count
if ($count -gt 1) {
    Write-Host "WARNING: $count postgres containers are running - 'symfony serve' may inject a different DATABASE_URL than .env!"
}

Write-Host ""
Write-Host "== DATABASE_URL the app resolves (password redacted) =="
$code1 = @'
<?php
require "vendor/autoload.php";
if (!isset($_SERVER["APP_ENV"])) { (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env", "dev"); }
$url = (string) ($_SERVER["DATABASE_URL"] ?? $_ENV["DATABASE_URL"] ?? getenv("DATABASE_URL"));
$p = parse_url($url);
printf("host=%s port=%s dbname=%s user=%s\n", $p["host"] ?? "?", $p["port"] ?? "5432", ltrim($p["path"] ?? "?", "/"), $p["user"] ?? "?");
'@
$tmp1 = New-TemporaryFile
Set-Content -Path $tmp1 -Value $code1 -Encoding Ascii
php $tmp1
Remove-Item $tmp1 -ErrorAction SilentlyContinue
if ($LASTEXITCODE -ne 0) { Write-Host "ERROR: could not resolve DATABASE_URL."; exit 1 }

Write-Host ""
Write-Host "== SELECT current_database() via the app connection =="
$code2 = @'
<?php
require "vendor/autoload.php";
if (!isset($_SERVER["APP_ENV"])) { (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env", "dev"); }
$url = (string) ($_SERVER["DATABASE_URL"] ?? $_ENV["DATABASE_URL"] ?? getenv("DATABASE_URL"));
$p = parse_url($url);
$dsn = sprintf("pgsql:host=%s;port=%d;dbname=%s", $p["host"] ?? "127.0.0.1", $p["port"] ?? 5432, ltrim($p["path"] ?? "/", "/"));
try {
    $pdo = new PDO($dsn, $p["user"] ?? "postgres", $p["pass"] ?? "");
    printf("current_database = %s\n", $pdo->query("SELECT current_database()")->fetchColumn());
} catch (Throwable $e) {
    echo "ERROR: cannot connect (is docker compose up -d?)\n";
    exit(1);
}
'@
$tmp2 = New-TemporaryFile
Set-Content -Path $tmp2 -Value $code2 -Encoding Ascii
php $tmp2
Remove-Item $tmp2 -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "== Migration status =="
php bin/console doctrine:migrations:up-to-date

Write-Host ""
Write-Host "== doctrine:schema:validate =="
php bin/console doctrine:schema:validate --skip-sync -q *> $null; $m = $LASTEXITCODE
php bin/console doctrine:schema:validate --skip-mapping *> $null; $s = $LASTEXITCODE
if ($m -eq 0 -and $s -eq 0) { Write-Host "[OK] mapping + database schema are in sync" }
else { Write-Host "[FAIL] schema drift - run: php bin/check-schema --dev && php bin/console doctrine:fixtures:load" }

Write-Host ""
Write-Host "== JWT keys present? =="
if (Test-Path config/jwt/private.pem) { Write-Host "[OK] config/jwt/private.pem exists (values never printed)" }
else { Write-Host "MISSING: run php bin/console lexik:jwt:generate-keypair" }
