<?php
/**
 * E-SARABAN Bootstrap File
 * Initializes all required components
 */

// Suppress PHP warnings/notices in production
if (getenv('APP_ENV') !== 'development') {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Require main configuration
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';

// Session handling
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Keep the reusable signature status available to every authenticated page.
// The lazy lookup also supports users who were already logged in before the
// signature column/session value was introduced.
if (!empty($_SESSION['user_id']) && !array_key_exists('signature', $_SESSION)) {
    try {
        $currentSignature = \Config\Database::fetchOne(
            'SELECT signature FROM users WHERE id = ?',
            [(int) $_SESSION['user_id']]
        );
        $_SESSION['signature'] = $currentSignature['signature'] ?? '';
    } catch (\Throwable $e) {
        $_SESSION['signature'] = '';
    }
}

// Global helper function to get config value
function config($key, $default = null) {
    return constant($key) ?? $default;
}

// Global helper to get database instance
function db() {
    return \Config\Database::getInstance();
}

// Global helper to log errors
function logError($message, $details = [], $level = 'error') {
    try {
        $logDir = ROOT_PATH . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/' . date('Y-m-d') . '.log';

        // Normalize details to array for consistent JSON output
        if (!is_array($details)) {
            $details = ['details' => $details];
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'details' => $details,
            'user' => $_SESSION['username'] ?? null,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null
        ];

        // Append JSON line (JSONL) for easy parsing
        file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    } catch (\Exception $e) {
        error_log("Failed to write structured log: " . $e->getMessage());
    }
}

// Global helper for response JSON
function respondJson($success, $data = null, $message = '', $statusCode = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    exit;
}

// Global helper to check user role
function checkRole($required_roles) {
    if (!is_array($required_roles)) {
        $required_roles = [$required_roles];
    }
    
    $userRole = $_SESSION['role'] ?? null;
    return in_array($userRole, $required_roles);
}

// Global helper to get current user
function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'name' => $_SESSION['name'] ?? null,
        'role' => $_SESSION['role'] ?? null,
        'department' => $_SESSION['department'] ?? null,
        'photo' => $_SESSION['photo'] ?? null,
        'signature' => $_SESSION['signature'] ?? null
    ];
}

// Global helper for URI generation
function uri($path, $withBase = true) {
    $base = $withBase ? APP_URL : '';
    return $base . '/' . ltrim($path, '/');
}

// Global helper for asset path
function asset($path) {
    return PUBLIC_URL . '/assets/' . ltrim($path, '/');
}

// Global helper to generate a safe download URL for uploaded files.
function upload_url($relativePath) {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $relativePath)) {
        return $relativePath;
    }

    if (stripos($relativePath, '/download.php?file=') !== false) {
        $parts = parse_url($relativePath);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $relativePath = (string) ($query['file'] ?? '');
        }
    }

    $relativePath = str_replace('\\', '/', urldecode($relativePath));
    $relativePath = ltrim($relativePath, '/');
    if (stripos($relativePath, 'uploads/') === 0) {
        $relativePath = substr($relativePath, 8);
    }

    $publicAssetPrefixes = ['logo/', 'favicon/', 'login/'];
    foreach ($publicAssetPrefixes as $prefix) {
        if (stripos($relativePath, $prefix) === 0) {
            $segments = array_map('rawurlencode', array_filter(explode('/', $relativePath), static function ($segment) {
                return $segment !== '';
            }));
            return PUBLIC_URL . '/' . implode('/', $segments);
        }
    }

    if ($relativePath === '') {
        return '';
    }

    return signed_upload_url($relativePath);
}

function download_link_secret() {
    $secret = trim((string) ($_ENV['DOWNLOAD_LINK_SECRET'] ?? ''));
    if ($secret !== '') {
        return $secret;
    }

    return hash('sha256', APP_NAME . '|' . ($_ENV['DB_NAME'] ?? '') . '|' . ($_ENV['DB_PASS'] ?? '') . '|' . APP_URL);
}

function signed_upload_url($relativePath, $ttl = 604800) {
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || preg_match('#^https?://#i', $relativePath)) {
        return upload_url($relativePath);
    }

    $relativePath = str_replace('\\', '/', urldecode($relativePath));
    $relativePath = ltrim($relativePath, '/');
    if (stripos($relativePath, 'uploads/') === 0) {
        $relativePath = substr($relativePath, 8);
    }
    if ($relativePath === '') {
        return '';
    }

    $expires = time() + max(60, (int) $ttl);
    $signature = hash_hmac('sha256', $relativePath . '|' . $expires, download_link_secret());

    return PUBLIC_URL . '/download.php?file=' . rawurlencode($relativePath)
        . '&expires=' . $expires . '&signature=' . $signature;
}

// Global helper to get the centralized favicon endpoint.
function system_favicon_url() {
    return PUBLIC_URL . '/favicon.php';
}

// Global helper to get the dynamic CSS endpoint for theme and font settings.
function system_dynamic_css_url() {
    return PUBLIC_URL . '/assets/css/dynamic.php';
}

// Inject the current system favicon into any HTML page that contains a <head>.
function inject_system_favicon_links($html) {
    if (!is_string($html) || stripos($html, '</head>') === false) {
        return $html;
    }

    $faviconHref = htmlspecialchars(system_favicon_url(), ENT_QUOTES, 'UTF-8');
    $dynamicCssHref = htmlspecialchars(system_dynamic_css_url(), ENT_QUOTES, 'UTF-8');
    $manifestHref = htmlspecialchars(PUBLIC_URL . '/manifest.php', ENT_QUOTES, 'UTF-8');
    $serviceWorkerHref = htmlspecialchars(PUBLIC_URL . '/sw.js?v=2', ENT_QUOTES, 'UTF-8');
    $faviconLinks = "\n    <link rel=\"icon\" href=\"{$faviconHref}\">\n    <link rel=\"shortcut icon\" href=\"{$faviconHref}\">\n    <link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"{$faviconHref}\">\n    <link rel=\"manifest\" href=\"{$manifestHref}\">\n    <meta name=\"theme-color\" content=\"#1d4ed8\">\n    <meta name=\"mobile-web-app-capable\" content=\"yes\">\n    <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n    <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n    <meta name=\"apple-mobile-web-app-title\" content=\"" . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . "\">";
    $dynamicCssLink = "\n    <link rel=\"stylesheet\" href=\"{$dynamicCssHref}\" data-dynamic data-system-dynamic=\"1\">";

    $html = preg_replace(
        '#\s*<link\b[^>]*\brel=["\"][^"\"]*(?:icon|apple-touch-icon)[^"\"]*["\"][^>]*>\s*#i',
        "\n",
        $html
    );

    $html = preg_replace(
        '#\s*<link\b[^>]*href=["\"][^"\"]*/public/assets/css/dynamic\.php[^"\"]*["\"][^>]*>\s*#i',
        "\n",
        $html
    );

    $html = preg_replace('#</head>#i', $faviconLinks . $dynamicCssLink . "\n</head>", $html, 1);

    if (stripos($html, '</body>') === false) {
        return $html;
    }

    $pwaMarkup = <<<'HTML'
    <script>
    (function () {
        if (!('serviceWorker' in navigator)) return;
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('__PWA_SERVICE_WORKER__', {scope: '__PWA_SCOPE__'}).catch(function () {});
        });

    })();
    </script>
HTML;

    $pageSearchCss = <<<'CSS'
    <style>
        .system-page-search {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 1065;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .system-page-search-toggle {
            width: 54px;
            height: 54px;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #1d4ed8 0%, #0891b2 100%);
            color: #fff;
            box-shadow: 0 14px 32px rgba(8, 145, 178, 0.28);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .system-page-search-panel {
            width: min(360px, calc(100vw - 24px));
            padding: 14px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.98);
            border: 1px solid rgba(191, 219, 254, 0.95);
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(12px);
            display: none;
        }

        .system-page-search-panel.open {
            display: block;
        }

        .system-page-search-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .system-page-search-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 8px;
            align-items: center;
        }

        .system-page-search-input {
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            min-height: 42px;
            padding: 10px 14px;
            font-size: 0.92rem;
        }

        .system-page-search-btn {
            min-width: 42px;
            height: 42px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
        }

        .system-page-search-status {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #64748b;
            min-height: 1.2em;
        }

        @media (max-width: 768px) {
            .system-page-search {
                right: 12px;
                bottom: 12px;
            }
        }
    </style>
CSS;

    $pageSearchMarkup = <<<'HTML'
    <div class="system-page-search" id="systemPageSearch">
        <div class="system-page-search-panel" id="systemPageSearchPanel">
            <div class="system-page-search-title">ค้นหาหน้านี้</div>
            <div class="system-page-search-row">
                <input type="search" class="system-page-search-input" id="systemPageSearchInput" placeholder="พิมพ์คำที่ต้องการค้นหาในหน้าปัจจุบัน">
                <button type="button" class="system-page-search-btn" id="systemPageSearchPrev" aria-label="ค้นหาก่อนหน้า">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <button type="button" class="system-page-search-btn" id="systemPageSearchNext" aria-label="ค้นหาถัดไป">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="system-page-search-status" id="systemPageSearchStatus">ค้นหาข้อความที่มองเห็นในหน้าปัจจุบัน</div>
        </div>
        <button type="button" class="system-page-search-toggle" id="systemPageSearchToggle" title="ค้นหาหน้านี้">
            <i class="fas fa-search"></i>
        </button>
    </div>
    <script>
    (function () {
        const root = document.getElementById('systemPageSearch');
        const panel = document.getElementById('systemPageSearchPanel');
        const toggle = document.getElementById('systemPageSearchToggle');
        const input = document.getElementById('systemPageSearchInput');
        const nextBtn = document.getElementById('systemPageSearchNext');
        const prevBtn = document.getElementById('systemPageSearchPrev');
        const status = document.getElementById('systemPageSearchStatus');

        if (!root || !panel || !toggle || !input || !nextBtn || !prevBtn || !status) {
            return;
        }

        function setStatus(message) {
            status.textContent = message;
        }

        function runSearch(backward) {
            const keyword = input.value.trim();
            if (!keyword) {
                setStatus('กรอกคำที่ต้องการค้นหาก่อน');
                return;
            }

            if (typeof window.find !== 'function') {
                setStatus('เบราว์เซอร์นี้ไม่รองรับการค้นหาหน้านี้อัตโนมัติ');
                return;
            }

            const found = window.find(keyword, false, !!backward, true, false, false, false);
            setStatus(found ? `พบคำว่า "${keyword}" ในหน้านี้` : `ไม่พบคำว่า "${keyword}" ในหน้านี้`);
        }

        toggle.addEventListener('click', function () {
            const isOpen = panel.classList.toggle('open');
            if (isOpen) {
                input.focus();
                input.select();
            }
        });

        nextBtn.addEventListener('click', function () {
            runSearch(false);
        });

        prevBtn.addEventListener('click', function () {
            runSearch(true);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                runSearch(event.shiftKey);
            }

            if (event.key === 'Escape') {
                panel.classList.remove('open');
            }
        });

        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                panel.classList.add('open');
                input.focus();
                input.select();
            }
        });
    })();
    </script>
HTML;

    $html = preg_replace('#</head>#i', $pageSearchCss . "\n</head>", $html, 1);

    $pwaMarkup = str_replace(
        ['__PWA_SERVICE_WORKER__', '__PWA_SCOPE__'],
        [$serviceWorkerHref, htmlspecialchars(PUBLIC_URL . '/', ENT_QUOTES, 'UTF-8')],
        $pwaMarkup
    );

    if (empty($_SESSION['user_id'])) {
        $pageSearchCss = '';
        $pageSearchMarkup = '';
    }

    return preg_replace('#</body>#i', $pwaMarkup . "\n" . $pageSearchMarkup . "\n</body>", $html, 1);
}

// Setup autoloader if not using composer
if (!class_exists(\App\Controllers\BaseController::class)) {
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        $baseDir = APP_PATH . '/';
        
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            
            if (file_exists($file)) {
                require $file;
            }
        }
        
        $prefix = 'Config\\';
        $baseDir = ROOT_PATH . '/config/';
        
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            
            if (file_exists($file)) {
                require $file;
            }
        }
    });
}

// Set error handler for logging
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return;
    }
    
    logError($errstr, [
        'file' => $errfile,
        'line' => $errline,
        'errno' => $errno
    ]);
    
    if (DEBUG_MODE) {
        throw new \ErrorException($errstr, $errno, 0, $errfile, $errline);
    }
});

// Set exception handler for logging
set_exception_handler(function ($exception) {
    logError($exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    // If request is API, return JSON error payload
    $isApi = (strtolower(basename($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        $payload = ['success' => false, 'error' => 'Internal Server Error'];
        if (DEBUG_MODE) {
            $payload['debug'] = $exception->getMessage();
            $payload['trace'] = $exception->getTraceAsString();
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } else {
        if (DEBUG_MODE) {
            echo "<pre>";
            echo "Exception: " . $exception->getMessage() . "\n";
            echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
            echo "Trace:\n" . $exception->getTraceAsString();
            echo "</pre>";
        } else {
            http_response_code(500);
            echo "An error occurred. Please contact administrator.";
        }
    }
    
    exit;
});

// Initialize system
define('BOOTSTRAP_LOADED', true);
?>
