# tools/local-model.ps1 — تنزيل نموذج محلي وتشغيله وربطه بمنصة «قيمة وأثر».
#
#   Windows PowerShell:
#     cd C:\xampp\htdocs\qeemah-platform
#     powershell -ExecutionPolicy Bypass -File tools\local-model.ps1
#
# الخيارات:
#     -Model qwen2.5:7b     نموذج أكبر وأذكى (يحتاج ذاكرة أكثر)
#     -Model llama3.2:1b    الأصغر والأسرع للأجهزة الضعيفة
#     -SkipInstall          لا تثبّت Ollama، افترض أنه مثبّت
#
# السكربت آمن للتكرار: يتخطّى ما هو مثبّت أو منزّل بالفعل.

param(
    [string]$Model = 'qwen2.5:3b',
    [switch]$SkipInstall
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

function Say($msg, $color = 'Cyan') { Write-Host $msg -ForegroundColor $color }
function Step($n, $total, $msg) { Write-Host "`n[$n/$total] $msg" -ForegroundColor White }
function Ok($msg)   { Write-Host "  OK  $msg" -ForegroundColor Green }
function Bad($msg)  { Write-Host "  !!  $msg" -ForegroundColor Red }
function Info($msg) { Write-Host "      $msg" -ForegroundColor DarkGray }

Say "`n=== نموذج ذكاء اصطناعي محلي لمنصة قيمة وأثر ===" 'Green'
Say "يعمل بلا إنترنت، بلا مفتاح، وبلا أي تكلفة.`n" 'Gray'

# ── 1) هل Ollama مثبّت؟ ─────────────────────────────────────────────────────
Step 1 4 "التحقّق من Ollama"
$ollama = Get-Command ollama -ErrorAction SilentlyContinue
if (-not $ollama) {
    # مسار التثبيت الافتراضي على ويندوز
    $guess = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
    if (Test-Path $guess) { $ollama = $guess; $env:PATH += ";$(Split-Path $guess)" }
}

if (-not $ollama) {
    if ($SkipInstall) { Bad "Ollama غير مثبّت ولا يمكن تخطّي التثبيت."; exit 1 }

    Info "Ollama غير موجود — سيُنزّل المُثبّت الرسمي (~700 ميجابايت)."
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if ($winget) {
        Info "التثبيت عبر winget..."
        winget install --id Ollama.Ollama -e --accept-source-agreements --accept-package-agreements
    } else {
        $url  = 'https://ollama.com/download/OllamaSetup.exe'
        $dest = Join-Path $env:TEMP 'OllamaSetup.exe'
        Info "تنزيل المُثبّت من $url"
        Invoke-WebRequest -Uri $url -OutFile $dest -UseBasicParsing
        Info "تشغيل المُثبّت (قد يطلب إذناً)..."
        Start-Process -FilePath $dest -ArgumentList '/S' -Wait
    }

    $ollama = Get-Command ollama -ErrorAction SilentlyContinue
    if (-not $ollama) {
        $guess = Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe'
        if (Test-Path $guess) { $ollama = $guess } else {
            Bad "لم يكتمل التثبيت. ثبّت يدوياً من https://ollama.com/download ثم أعد تشغيل السكربت."
            exit 1
        }
    }
    Ok "تم تثبيت Ollama"
} else {
    Ok "Ollama مثبّت: $ollama"
}

# ── 2) تشغيل الخادم ─────────────────────────────────────────────────────────
Step 2 4 "تشغيل خادم النموذج"
function Test-Ollama {
    try {
        $r = Invoke-WebRequest -Uri 'http://127.0.0.1:11434/api/tags' -TimeoutSec 3 -UseBasicParsing
        return $r.StatusCode -eq 200
    } catch { return $false }
}

if (Test-Ollama) {
    Ok "الخادم يعمل بالفعل على 127.0.0.1:11434"
} else {
    Info "بدء التشغيل في الخلفية..."
    Start-Process -FilePath $ollama -ArgumentList 'serve' -WindowStyle Hidden
    $up = $false
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 700
        if (Test-Ollama) { $up = $true; break }
    }
    if ($up) { Ok "الخادم يعمل الآن" } else { Bad "لم يستجب الخادم. جرّب تشغيله يدوياً: ollama serve"; exit 1 }
}

# ── 3) تنزيل النموذج ────────────────────────────────────────────────────────
Step 3 4 "تنزيل النموذج: $Model"
Info "الحجم المتوقّع: من 1 إلى 5 جيجابايت حسب النموذج. يُنزّل مرة واحدة فقط."
& $ollama pull $Model
if ($LASTEXITCODE -ne 0) { Bad "فشل التنزيل. تحقّق من الاتصال بالإنترنت، أو جرّب نموذجاً أصغر: -Model llama3.2:1b"; exit 1 }
Ok "النموذج جاهز"

# ── 4) ربط المنصة به ────────────────────────────────────────────────────────
Step 4 4 "ربط المنصة بالنموذج"
$localFile   = Join-Path $root 'config\ai.local.php'
$exampleFile = Join-Path $root 'config\ai.local.example.php'

$config = @"
<?php
/**
 * أُنشئ تلقائياً بواسطة tools/local-model.ps1 — لا يحتوي أي سرّ.
 * عدّل model أو base_url إن غيّرت النموذج أو المنفذ.
 */
return [
    'base_url' => 'http://127.0.0.1:11434/v1',
    'model'    => '$Model',
    'provider' => 'ollama',
    'timeout'  => 90,
    'api_key'  => null,
];
"@

if (Test-Path $localFile) {
    $backup = "$localFile.bak"
    Copy-Item $localFile $backup -Force
    Info "نسخة احتياطية من الإعداد السابق: $backup"
}
Set-Content -Path $localFile -Value $config -Encoding UTF8
Ok "كُتب config/ai.local.php"

# فحص نهائي بسكربت المنصة نفسه
Info "تشغيل فحص الاتصال..."
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    $xamppPhp = 'C:\xampp\php\php.exe'
    if (Test-Path $xamppPhp) { $php = $xamppPhp }
}
if ($php) {
    & $php (Join-Path $root 'tools\ai_doctor.php')
} else {
    Info "لم أجد php في PATH — افتح http://localhost/qeemah-platform/admin/settings.php للتحقق."
}

Say "`n=== انتهى ===" 'Green'
Say "افتح المنصة واضغط زر «مساعد» — سيردّ النموذج المحلي." 'White'
Say "لإيقاف النموذج مؤقتاً: احذف config/ai.local.php (أو أعد تسميته)" 'Gray'
Say "لمراقبة الخادم: ollama list | ollama ps" 'Gray'
