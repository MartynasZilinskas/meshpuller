<?php
/**
 * MeshCore Administravimo Pulto Konfigūracija ir Autentifikacija
 */

// Nustatome Lietuvos laiko juostą
date_default_timezone_set('Europe/Vilnius');

// Saugus sesijos paleidimas
if (session_status() === PHP_SESSION_NONE) {
    // Saugios sesijos slapuko nustatymai
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 dienos
        'path'     => '/',
        'domain'   => $cookieParams['domain'],
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_name('meshcore_admin_session');
    session_start();
}

/**
 * Numatytojo administratoriaus slaptažodžio nustatymas.
 * Galite pasikeisti čia arba aplinkos kintamajame ADMIN_PASSWORD,
 * arba faile /Users/nerijus/Projects/read/www/admin/.password
 */
define('DEFAULT_ADMIN_PASSWORD', 'meshadmin2026');

/**
 * Grąžina aktyvų administratoriaus slaptažodį
 */
function getAdminPassword(): string
{
    // 1. Tikriname aplinkos kintamąjį
    $envPass = getenv('ADMIN_PASSWORD');
    if (!empty($envPass)) {
        return trim($envPass);
    }

    // 2. Tikriname lokalų .password failą, jei vartotojas jį susikūrė
    $passFile = __DIR__ . '/.password';
    if (file_exists($passFile)) {
        $content = trim(file_get_contents($passFile));
        if (!empty($content)) {
            return $content;
        }
    }

    return DEFAULT_ADMIN_PASSWORD;
}

/**
 * Patikrina ar vartotojas yra prisijungęs kaip administratorius
 */
function isAdminLoggedIn(): bool
{
    return !empty($_SESSION['meshcore_admin_logged']) && $_SESSION['meshcore_admin_logged'] === true;
}

/**
 * Administratoriaus prisijungimas
 */
function adminLogin(string $inputPassword): bool
{
    $realPassword = getAdminPassword();

    // Palaikome ir paprastą tekstą, ir password_hash hash'ą
    if ($inputPassword === $realPassword || (str_starts_with($realPassword, '$2y$') && password_verify($inputPassword, $realPassword))) {
        $_SESSION['meshcore_admin_logged'] = true;
        $_SESSION['meshcore_admin_login_time'] = time();
        $_SESSION['meshcore_admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        return true;
    }

    return false;
}

/**
 * Administratoriaus atsijungimas
 */
function adminLogout(): void
{
    $_SESSION['meshcore_admin_logged'] = false;
    unset($_SESSION['meshcore_admin_logged']);
    unset($_SESSION['meshcore_admin_login_time']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Reikalauja administratoriaus teisių API užklausoms
 */
function requireAdminAuthJson(): void
{
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Neautorizuota prieiga. Prašome prisijungti prie administravimo pulto.'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
