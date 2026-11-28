<?php
/**
 * config/ai.php
 * -----------------------------------------------------------------------------
 * Conversational assistant — configuration surface.
 *
 * TWO ways to run the assistant, in order of precedence:
 *
 *   A. A LOCAL model on this machine (no internet, no key, no cost).
 *      Recommended: `config/ai.local.php` — a tiny file you create once, which
 *      holds only a URL and a model name. It contains no secret, so it is safe
 *      to keep next to the project. See docs/LOCAL-MODEL.md.
 *
 *   B. A hosted API through the environment (a key is required):
 *        QEEMAH_AI_PROVIDER   openai | compatible
 *        QEEMAH_AI_API_KEY    the secret
 *        QEEMAH_AI_MODEL      e.g. gpt-4o-mini
 *        QEEMAH_AI_BASE_URL   default https://api.openai.com/v1
 *        QEEMAH_AI_TIMEOUT    seconds, default 12 (60 for a local model)
 *
 * Environment variables win over ai.local.php, so a deployment can override the
 * file without editing it.
 *
 * When neither is configured the public entry points fall back to the written
 * guide + live MySQL numbers, and every page keeps working.
 *
 * Privacy: only the user's own question is stored, in `assistant_threads`, and
 * the retention window is enforced from the admin settings page. Product data
 * sent to the model is the public listing fields only — never phones, addresses
 * or beneficiary records. A local model never sends anything off the machine.
 */
declare(strict_types=1);

namespace Qeemah\Config;

final class Ai
{
    /** Parsed config/ai.local.php, or null when the file does not exist. */
    private static ?array $local = null;
    private static bool $localLoaded = false;

    private static function env(string $name): ?string
    {
        $v = getenv($name);
        return ($v === false || trim((string)$v) === '') ? null : trim((string)$v);
    }

    /**
     * Read config/ai.local.php once. The file is optional and returns an array:
     *   ['base_url' => 'http://127.0.0.1:11434/v1', 'model' => 'qwen2.5:3b',
     *    'provider' => 'ollama', 'timeout' => 90, 'api_key' => null]
     */
    public static function localConfig(): array
    {
        if (self::$localLoaded) {
            return self::$local ?? [];
        }
        self::$localLoaded = true;
        $file = QEEMAH_ROOT . '/config/ai.local.php';
        if (!is_file($file)) {
            return [];
        }
        $cfg = require $file;           // isolated: expects a plain array back
        self::$local = is_array($cfg) ? $cfg : [];
        return self::$local;
    }

    private static function cfg(string $key): ?string
    {
        $v = self::localConfig()[$key] ?? null;
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public static function baseUrl(): string
    {
        $url = self::env('QEEMAH_AI_BASE_URL') ?? self::cfg('base_url');
        return $url !== null ? rtrim($url, '/') : 'https://api.openai.com/v1';
    }

    /**
     * A key is optional for a local model (Ollama and LM Studio accept none).
     * Some local servers do want a placeholder, hence 'api_key' in the file.
     */
    public static function apiKey(): ?string
    {
        return self::env('QEEMAH_AI_API_KEY') ?? self::cfg('api_key');
    }

    public static function model(): string
    {
        return self::env('QEEMAH_AI_MODEL') ?? self::cfg('model') ?? 'gpt-4o-mini';
    }

    public static function provider(): string
    {
        $p = self::env('QEEMAH_AI_PROVIDER') ?? self::cfg('provider');
        if ($p !== null) {
            return strtolower($p);
        }
        return self::isLocal() ? 'ollama' : 'openai';
    }

    /** True when the endpoint lives on this machine or the local network. */
    public static function isLocal(): bool
    {
        $host = parse_url(self::baseUrl(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower(trim($host, '[]'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'host.docker.internal'], true)) {
            return true;
        }
        // 10.x, 192.168.x, 172.16–31.x — a model on another machine at home/office.
        return (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    }

    /**
     * CPU inference is slow: a 3B model can take 20–40s on the first answer.
     * Local runs therefore default to a generous timeout, capped at 120s.
     */
    public static function timeout(): int
    {
        $raw = self::env('QEEMAH_AI_TIMEOUT') ?? self::cfg('timeout');
        $t   = $raw !== null ? (int)$raw : (self::isLocal() ? 60 : 12);
        $max = self::isLocal() ? 120 : 60;
        return $t > 0 && $t <= $max ? $t : ($t > $max ? $max : ($t <= 0 ? ($max === 120 ? 60 : 12) : $t));
    }

    /** Is a model configured at all — key OR a local endpoint? */
    public static function configured(): bool
    {
        return self::apiKey() !== null || self::localConfig() !== [];
    }

    /**
     * True only when a model is configured AND the admin switch is on.
     * A local endpoint does not need a key.
     */
    public static function ready(bool $settingsEnabled): bool
    {
        return $settingsEnabled && self::configured();
    }

    /** Short human label for the settings page and the chat bubble. */
    public static function describe(): string
    {
        return self::isLocal()
            ? 'نموذج محلي · ' . self::model() . ' · ' . self::baseUrl()
            : self::provider() . ' · ' . self::model();
    }
}
