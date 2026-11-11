# PREPARED, NEVER RUN in this round: no live provider, no running backend and no
# test account were available here.
#
# Owner-run smoke test for the AI assistant, against a REAL backend and the REAL
# provider. It proves the whole chain works end to end: login -> /api/agent/chat
# -> provider answer -> persisted conversation.
#
# Credentials come ONLY from environment variables in the current shell, so
# nothing secret is ever typed on the command line, stored in this file, or
# printed by it:
#
#   $env:SMOKE_EMAIL    = 'smoke-user@example.test'
#   $env:SMOKE_PASSWORD = '<that account password>'
#   powershell -File bin/agent-smoke.ps1
#
# The provider key belongs to the BACKEND PROCESS, not to this script:
# ANTHROPIC_API_KEY must be in backend/.env.local (AGENT_ENABLED=true too).
# A 404 AGENT_DISABLED or 503 AGENT_LLM_UNAVAILABLE below means exactly that.
#
# Exit codes: 0 = smoke passed, 1 = smoke failed, 2 = missing local setup.

[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://127.0.0.1:8000',
    [string]$Prompt = 'Reply with the single word READY.'
)

$ErrorActionPreference = 'Stop'

$email = $env:SMOKE_EMAIL
$password = $env:SMOKE_PASSWORD

if ([string]::IsNullOrWhiteSpace($email) -or [string]::IsNullOrWhiteSpace($password)) {
    Write-Host 'SKIP: set SMOKE_EMAIL and SMOKE_PASSWORD in this shell first (never as script arguments).'
    exit 2
}

if ([string]::IsNullOrWhiteSpace($env:ANTHROPIC_API_KEY)) {
    Write-Host 'NOTE: ANTHROPIC_API_KEY is not set in THIS shell. That is fine only if the backend process has it in .env.local.'
}

Write-Host "Agent smoke test against $BaseUrl"

# ---------------------------------------------------------------- 1. login ---
$loginBody = @{ email = $email; password = $password } | ConvertTo-Json

try {
    $login = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/auth/login" `
        -ContentType 'application/json' -Body $loginBody -TimeoutSec 20
} catch {
    Write-Host "FAIL login request: $($_.Exception.Message)"
    exit 1
}

$token = $login.data.token
if ([string]::IsNullOrWhiteSpace($token)) {
    Write-Host 'FAIL login: response contained no access token (check the account credentials).'
    exit 1
}
Write-Host 'login: ok'

# ----------------------------------------------------------------- 2. chat ---
$headers = @{ Authorization = "Bearer $token" }
$chatBody = @{ message = $Prompt } | ConvertTo-Json

$watch = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $response = Invoke-WebRequest -Method Post -Uri "$BaseUrl/api/agent/chat" `
        -Headers $headers -ContentType 'application/json' -Body $chatBody `
        -TimeoutSec 90 -SkipHttpErrorCheck
} catch {
    Write-Host "FAIL chat request: $($_.Exception.Message)"
    exit 1
}
$watch.Stop()

$status = [int]$response.StatusCode
Write-Host ("chat: HTTP {0} in {1:N1}s" -f $status, $watch.Elapsed.TotalSeconds)

$payload = $null
try { $payload = $response.Content | ConvertFrom-Json } catch { }

if ($status -ne 200) {
    $code = $payload.error.code
    Write-Host "FAIL chat: status $status, errorCode '$code'"
    switch ($code) {
        'AGENT_DISABLED'        { Write-Host '  -> set AGENT_ENABLED=true in backend/.env.local and restart.' }
        'AGENT_LLM_UNAVAILABLE' { Write-Host '  -> set ANTHROPIC_API_KEY in backend/.env.local and restart.' }
        'AGENT_RATE_LIMITED'    { Write-Host '  -> wait for the interval in Retry-After, then rerun.' }
        'AGENT_DAILY_TOKEN_CAP' { Write-Host '  -> the account is out of daily tokens; rerun tomorrow (UTC).' }
        default                 { Write-Host '  -> check backend/var/log/prod.log for the request id.' }
    }
    exit 1
}

# Envelope: { success, data: { conversationId, message: { content, ... } }, meta: { usage } }
$reply = [string]$payload.data.message.content
if ([string]::IsNullOrWhiteSpace($reply)) {
    Write-Host 'FAIL chat: the turn returned no reply text.'
    exit 1
}

Write-Host ("reply: {0} character(s)" -f $reply.Length)
if (-not [string]::IsNullOrWhiteSpace($payload.data.conversationId)) {
    Write-Host ("conversationId: {0}" -f $payload.data.conversationId)
}
if ($null -ne $payload.meta.usage) {
    Write-Host ("tokens: input={0} output={1}" -f $payload.meta.usage.inputTokens, $payload.meta.usage.outputTokens)
}

Write-Host 'AGENT SMOKE OK'
exit 0
