<?php
declare(strict_types=1);

define('LOGIN_URL', 'https://www.root-me.org/spip.php?page=login&lang=fr&ajax=1');
define('NEWS_URL',  'https://www.root-me.org/?page=news&lang=fr');
define('COOKIE_FILE', __DIR__ . '/.rootme_cookie.txt');

/**
 * Build a URL‐encoded query string from an associative array.
 */
function urlify(array $fields = []): string
{
    $pairs = [];
    foreach ($fields as $k => $v) {
        $pairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
    }
    return implode('&', $pairs);
}

/**
 * Perform a GET request to $url, persisting cookies in COOKIE_FILE.
 */
function get_request(string $url): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Failed to init cURL for GET');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_COOKIEJAR      => COOKIE_FILE,
    ]);
    $resp = curl_exec($curl);
    if ($resp === false) {
        $err = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException("cURL GET error: $err");
    }
    curl_close($curl);
    return $resp;
}

/**
 * Perform a POST request to $url with $fields (array), persisting cookies.
 */
function post_request(string $url, array $fields): string
{
    $postData = urlify($fields);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Failed to init cURL for POST');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_COOKIEJAR      => COOKIE_FILE,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
    ]);
    $resp = curl_exec($curl);
    if ($resp === false) {
        $err = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException("cURL POST error: $err");
    }
    curl_close($curl);
    return $resp;
}

/**
 * Extract the CSRF token (hidden input named formulaire_action_args) from the login HTML.
 */
function parse_form_action_args(string $html): ?string
{
    // Matches: <input name="formulaire_action_args" type="hidden" value="…">
    $pattern = '/<input\s+name=["\']formulaire_action_args["\']\s+type=["\']hidden["\']\s+value=["\']([^"\']+)["\']\s*\/?>/i';
    if (preg_match($pattern, $html, $m) !== 1) {
        return null;
    }
    return $m[1];
}

/**
 * POST the login form using CSRF token, username, and plain password.
 */
function connexion(string $token, string $login, string $password): string
{
    $fields = [
        'var_ajax'               => 'form',
        'page'                   => 'login',
        'lang'                   => 'fr',
        'ajax'                   => '1',
        'formulaire_action'      => 'login',
        'formulaire_action_args' => $token,
        'var_login'              => $login,
        'password'               => $password,
    ];
    return post_request(LOGIN_URL, $fields);
}

/**
 * Prompt for username via STDIN.
 */
function input_login(): string
{
    echo 'Login : ';
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('Failed to read login from STDIN.');
    }
    return rtrim($line, "\r\n");
}

/**
 * Prompt for password (no echo).
 */
function input_password(): string
{
    echo 'Password : ';
    shell_exec('stty -echo');
    $line = fgets(STDIN);
    shell_exec('stty echo');
    echo "\n";
    if ($line === false) {
        throw new RuntimeException('Failed to read password from STDIN.');
    }
    return rtrim($line, "\r\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Main script
// ─────────────────────────────────────────────────────────────────────────────

if (file_exists(COOKIE_FILE)) {
    @unlink(COOKIE_FILE);
}

echo "=======================================\n";
echo "# Root-Me login script (PHP 8)        #\n";
echo "=======================================\n";

try {
    $LOGIN    = input_login();
    $PASSWORD = input_password();

    // Step 1: GET the AJAX login page to extract CSRF token:
    $loginPage = get_request(LOGIN_URL);
    $token     = parse_form_action_args($loginPage);
    if ($token === null) {
        fwrite(STDERR,
            "Error: could not find CSRF token 'formulaire_action_args' in login page.\n".
            ">>> Here is a snippet of the response:\n".
            substr($loginPage, 0, 500) . "\n"
        );
        exit(1);
    }

    // Step 2: POST username + plaintext password:
    $loginResult = connexion($token, $LOGIN, $PASSWORD);

    // Step 3: Check for success indicator in response:
    if (strpos($loginResult, 'Vous êtes enregistré') !== false) {
        echo "[+] Login Success\n";

        // Step 4: Verify we’re really logged in by hitting the news page:
        $afterLogin = get_request(NEWS_URL);
        if (strpos($afterLogin, 'Se déconnecter') !== false) {
            echo "[+] Connected to SPIP successfully\n";
            echo "Don't forget to send me your flags in PM ;)\n";
        } else {
            echo "[-] SPIP connection check failed\n";
        }
    } else {
        echo "[-] Login Failed\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
