#!/usr/bin/env bash
# tools/local-model.sh — تنزيل نموذج محلي وتشغيله وربطه بمنصة «قيمة وأثر».
#
#   macOS / Linux:
#     cd /path/to/qeemah-platform
#     bash tools/local-model.sh                    # qwen2.5:3b (موصى به)
#     bash tools/local-model.sh qwen2.5:7b         # أكبر وأذكى
#     bash tools/local-model.sh llama3.2:1b        # للأجهزة الضعيفة
#
# آمن للتكرار: يتخطّى ما هو مثبّت أو منزّل بالفعل.

set -euo pipefail

MODEL="${1:-qwen2.5:3b}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$*"; }
red()   { printf '\033[31m%s\033[0m\n' "$*"; }
gray()  { printf '\033[90m%s\033[0m\n' "$*"; }
step()  { printf '\n\033[1m[%s] %s\033[0m\n' "$1" "$2"; }

printf '\n\033[32m=== نموذج ذكاء اصطناعي محلي لمنصة قيمة وأثر ===\033[0m\n'
gray "يعمل بلا إنترنت، بلا مفتاح، وبلا أي تكلفة."

# ── 1) تثبيت Ollama ─────────────────────────────────────────────────────────
step 1/4 "التحقّق من Ollama"
if ! command -v ollama >/dev/null 2>&1; then
    gray "Ollama غير موجود — سيُنزّل ويُثبّت الآن."
    if [[ "$(uname -s)" == "Darwin" ]]; then
        gray "التنزيل لـ macOS (قد يطلب كلمة المرور لإدخال التطبيق)..."
        curl -fsSL https://ollama.com/download/Ollama-darwin.zip -o /tmp/Ollama.zip
        unzip -oq /tmp/Ollama.zip -d /Applications
        ollama_path="/Applications/Ollama.app/Contents/Resources/ollama"
        if [[ -x "$ollama_path" ]]; then
            sudo ln -sf "$ollama_path" /usr/local/bin/ollama 2>/dev/null || true
        fi
    else
        gray "التثبيت عبر السكربت الرسمي..."
        curl -fsSL https://ollama.com/install.sh | sh
    fi
fi
command -v ollama >/dev/null 2>&1 || { red "لم يكتمل تثبيت Ollama. ثبّته يدوياً من https://ollama.com/download"; exit 1; }
green "Ollama جاهز: $(command -v ollama)"

# ── 2) تشغيل الخادم ─────────────────────────────────────────────────────────
step 2/4 "تشغيل خادم النموذج"
up() { curl -fsS --max-time 3 http://127.0.0.1:11434/api/tags >/dev/null 2>&1; }
if up; then
    green "الخادم يعمل بالفعل على 127.0.0.1:11434"
else
    gray "بدء التشغيل في الخلفية..."
    (ollama serve >/tmp/ollama-serve.log 2>&1 &) || true
    for _ in $(seq 1 20); do sleep 1; up && break; done
    up && green "الخادم يعمل الآن" || { red "لم يستجب الخادم. راجع /tmp/ollama-serve.log"; exit 1; }
fi

# ── 3) تنزيل النموذج ────────────────────────────────────────────────────────
step 3/4 "تنزيل النموذج: $MODEL"
gray "من 1 إلى 5 جيجابايت حسب النموذج. يُنزّل مرة واحدة فقط."
ollama pull "$MODEL" || { red "فشل التنزيل. جرّب نموذجاً أصغر: bash tools/local-model.sh llama3.2:1b"; exit 1; }
green "النموذج جاهز"

# ── 4) الربط ────────────────────────────────────────────────────────────────
step 4/4 "ربط المنصة بالنموذج"
LOCAL="$ROOT/config/ai.local.php"
if [[ -f "$LOCAL" ]]; then
    cp "$LOCAL" "$LOCAL.bak"
    gray "نسخة احتياطية: $LOCAL.bak"
fi
cat > "$LOCAL" <<PHP
<?php
/**
 * أُنشئ تلقائياً بواسطة tools/local-model.sh — لا يحتوي أي سرّ.
 */
return [
    'base_url' => 'http://127.0.0.1:11434/v1',
    'model'    => '${MODEL}',
    'provider' => 'ollama',
    'timeout'  => 90,
    'api_key'  => null,
];
PHP
green "كُتب config/ai.local.php"

if command -v php >/dev/null 2>&1; then
    gray "تشغيل فحص الاتصال..."
    php "$ROOT/tools/ai_doctor.php" || true
else
    gray "لم أجد php في PATH — افتح صفحة إعدادات الإدارة للتحقق."
fi

printf '\n'; green "=== انتهى ==="
echo "افتح المنصة واضغط زر «مساعد» — سيردّ النموذج المحلي."
gray "لإيقاف النموذج: أعد تسمية config/ai.local.php"
gray "لمراقبة الخادم: ollama list | ollama ps"
