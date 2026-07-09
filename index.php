<?php
/**
 * Rolplay Multi-Tenant Analytics Bridge — Front Controller
 *
 * Resolves the tenant from the X-Tenant header (set by nginx based on which
 * location block matched — /sanfer/bridge/ or /apotex/bridge/, unchanged
 * from before), defines that tenant's DB credentials as PHP constants from
 * environment variables, then requires that tenant's business-logic file
 * UNCHANGED from the original single-tenant bridge.
 *
 * Each tenant's query logic, caching, and response shape are 100% preserved —
 * this file only centralizes credential management and container/deploy
 * infrastructure. See tenants/sanfer.php and tenants/apotex.php.
 *
 * Adding a new tenant:
 *   1. Drop their existing bridge file into tenants/<name>.php
 *   2. Remove their hardcoded DB credential defines (keep business logic as-is)
 *   3. Add an entry to TENANTS below
 *   4. Set the <NAME>_DB_* environment variables on the container
 *   5. Add an nginx location block with `proxy_set_header X-Tenant <name>;`
 */
declare(strict_types=1);

// Started here (not per-tenant) so _bridge.ms reflects true end-to-end time.
define('BRIDGE_START', microtime(true));

// ── Tenant registry — maps tenant slug to its required env var prefixes ──
// Each entry lists which constants to define, and which env var to read
// each one from. No credentials live in this file or in git.
const TENANTS = [
    'sanfer' => [
        'file' => __DIR__ . '/tenants/sanfer.php',
        'env'  => [
            'DB_HOST'     => 'SANFER_DB_HOST',
            'DB_PORT'     => 'SANFER_DB_PORT',
            'DB_NAME'     => 'SANFER_DB_NAME',
            'DB_USER'     => 'SANFER_DB_USER',
            'DB_PASS'     => 'SANFER_DB_PASS',
            'DB_COLL'     => 'SANFER_DB_COLL',
            'ORG_DB_HOST' => 'SANFER_ORG_DB_HOST',
            'ORG_DB_PORT' => 'SANFER_ORG_DB_PORT',
            'ORG_DB_NAME' => 'SANFER_ORG_DB_NAME',
            'ORG_DB_USER' => 'SANFER_ORG_DB_USER',
            'ORG_DB_PASS' => 'SANFER_ORG_DB_PASS',
            'OFF_DB_HOST' => 'SANFER_OFF_DB_HOST',
            'OFF_DB_PORT' => 'SANFER_OFF_DB_PORT',
            'OFF_DB_NAME' => 'SANFER_OFF_DB_NAME',
            'OFF_DB_USER' => 'SANFER_OFF_DB_USER',
            'OFF_DB_PASS' => 'SANFER_OFF_DB_PASS',
        ],
    ],
    'apotex' => [
        'file' => __DIR__ . '/tenants/apotex.php',
        'env'  => [
            'DB_HOST'  => 'APOTEX_DB_HOST',
            'DB_NAME'  => 'APOTEX_DB_NAME',
            'DB_USER'  => 'APOTEX_DB_USER',
            'DB_PASS'  => 'APOTEX_DB_PASS',
            'DB2_HOST' => 'APOTEX_DB2_HOST',
            'DB2_NAME' => 'APOTEX_DB2_NAME',
            'DB2_USER' => 'APOTEX_DB2_USER',
            'DB2_PASS' => 'APOTEX_DB2_PASS',
        ],
    ],
];

$tenant = $_SERVER['HTTP_X_TENANT'] ?? '';

if (!array_key_exists($tenant, TENANTS)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unknown or missing tenant']);
    exit;
}

$cfg = TENANTS[$tenant];

foreach ($cfg['env'] as $constant => $envVar) {
    $value = getenv($envVar);
    if ($value === false || $value === '') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => "Bridge misconfigured — missing environment variable: $envVar",
        ]);
        exit;
    }
    // DB_PORT-style values must be ints for the PDO DSN string interpolation
    // used by the tenant files; is_numeric env values are cast accordingly.
    define($constant, ctype_digit($value) ? (int)$value : $value);
}

require $cfg['file'];
