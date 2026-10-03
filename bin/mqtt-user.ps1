<#
.SYNOPSIS
    Create or update a Mosquitto broker user for the 3awedlou local dev broker.

.DESCRIPTION
    Runs mosquitto_passwd INSIDE the broker container (or a one-shot container
    when the broker is not running yet) so the hashed password file stays on
    the host at docker/mosquitto/passwd, which is GIT-IGNORED.

    Security rules honoured here:
      * the password comes from the MQTT_USER_PASSWORD environment variable or
        from a hidden interactive prompt; it is never printed, never logged and
        never placed in a host-side command line - only piped over stdin;
      * nothing is written to a git-tracked file.

.PARAMETER Username
    Broker username. For a MACHINE this MUST equal Machine.identifier
    (e.g. 3awedlou-001) because the broker ACL pins each device to
    {MQTT_PREFIX}/machines/%u/... - the name and the topic must match.
    The backend's own user is called `backend`.

.EXAMPLE
    .\bin\mqtt-user.ps1 backend
.EXAMPLE
    $env:MQTT_USER_PASSWORD = '...'; .\bin\mqtt-user.ps1 3awedlou-001
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string] $Username,

    [string] $ContainerName = '3awedlou-mosquitto',
    [string] $Image = 'eclipse-mosquitto:2'
)

$ErrorActionPreference = 'Stop'

if ($Username -notmatch '^[a-z0-9][a-z0-9\-_]{1,63}$') {
    throw "Invalid username '$Username'. Expected a machine identifier: letters, digits, dash or underscore, 2-64 chars (e.g. 3awedlou-001, or 'backend')."
}

# --- password: env var first, hidden prompt otherwise -----------------------
$password = $env:MQTT_USER_PASSWORD
if ([string]::IsNullOrEmpty($password)) {
    $secure = Read-Host -AsSecureString "Password for MQTT user '$Username'"
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
}
if ([string]::IsNullOrEmpty($password)) {
    throw 'Refusing to create a broker user with an empty password.'
}

$repoRoot = Split-Path -Parent $PSScriptRoot
$configDir = (Join-Path (Join-Path $repoRoot 'docker') 'mosquitto') -replace '\\', '/'

$running = @(
    docker ps --format '{{.Names}}' | Where-Object { $_ -eq $ContainerName }
).Count -gt 0

# The password travels on stdin only: never as an argument on this machine.
$previousEncoding = $OutputEncoding
$OutputEncoding = [Text.UTF8Encoding]::new($false)
try {
    if ($running) {
        Write-Host "Updating broker user '$Username' in running container $ContainerName ..."
        $password | docker exec -i $ContainerName sh -c 'IFS= read -r P; exec mosquitto_passwd -b /mosquitto/config/passwd "$1" "$P"' sh $Username
    }
    else {
        Write-Host "Container '$ContainerName' is not running - creating docker/mosquitto/passwd with a one-shot container ..."
        $password | docker run --rm -i -v "${configDir}:/mosquitto/config" $Image sh -c 'IFS= read -r P; exec mosquitto_passwd -b /mosquitto/config/passwd "$1" "$P"' sh $Username
    }
}
finally {
    $OutputEncoding = $previousEncoding
    $password = $null
}

if ($LASTEXITCODE -ne 0) {
    throw "mosquitto_passwd failed (exit code $LASTEXITCODE)."
}

Write-Host "OK: user '$Username' written to docker/mosquitto/passwd (hashed, git-ignored)."
if ($running) {
    Write-Host 'NOTE: Mosquitto loads the password file at startup - restart the broker to pick up the change:'
    Write-Host "      docker compose -f compose.mqtt.yaml restart mosquitto"
}
