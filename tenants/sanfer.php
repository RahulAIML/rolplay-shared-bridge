<?php
/**
 * Sanfer tenant — business logic verbatim from the original single-tenant
 * sanfer_bridge_index.php. DB credentials are NOT defined here — the shared
 * front controller (../index.php) defines DB_HOST/DB_PORT/... etc from
 * environment variables (SANFER_DB_*, SANFER_ORG_DB_*, SANFER_OFF_DB_*)
 * BEFORE requiring this file. Everything below this point is unchanged.
 */
declare(strict_types=1);
@ini_set("display_errors","0");
error_reporting(0);
// Wide date windows return thousands of rows with large HTML blobs —
// the default 128M limit dies around the 90-day mark.
@ini_set("memory_limit","512M");
// gzip JSON responses when the client supports it (~8-10x smaller payloads)
if (!in_array("ob_gzhandler", ob_list_handlers(), true)) @ob_start("ob_gzhandler");
define("DB_CLIENT","sanfer");
// Cert line IDs — same values as CERT_LINES[*].tagId in certification.ts
define("CERT_LINE_IDS","1,2,3,5,6,7,8,9,10,11,12,23,24,25,28");
// Tester exclusion — exact condition used by official platform queries (from queries_Sanfer.pdf)
define("TESTER_EXCL","m.mb_user LIKE '%tester%' OR m.mb_user LIKE '%prueba%' OR m.mb_user LIKE '%demo%' OR m.mb_user LIKE '%capacitacion%' OR m.mb_user LIKE '%capacitación%' OR m.mb_user LIKE '%vacante%' OR m.mb_fullname LIKE '%tester%' OR m.mb_fullname LIKE '%prueba%' OR m.mb_fullname LIKE '%demo%' OR m.mb_fullname LIKE '%capacitacion%' OR m.mb_fullname LIKE '%capacitación%' OR m.mb_fullname LIKE '%vacante%'");

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if($_SERVER["REQUEST_METHOD"]==="OPTIONS"){http_response_code(204);exit;}

function pdo(): PDO {
    static $p=null;
    if($p) return $p;
    $p = new PDO(
        "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=".DB_COLL,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $p;
}

function org_pdo(): PDO {
    static $p=null;
    if($p) return $p;
    $p = new PDO(
        "mysql:host=".ORG_DB_HOST.";port=".ORG_DB_PORT.";dbname=".ORG_DB_NAME.";charset=utf8mb4",
        ORG_DB_USER, ORG_DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $p;
}

/** Official Rolplay platform DB — same source as rolplaysanfer.com cert page. */
function official_pdo(): PDO {
    static $p=null;
    if($p) return $p;
    $p = new PDO(
        "mysql:host=".OFF_DB_HOST.";port=".OFF_DB_PORT.";dbname=".OFF_DB_NAME.";charset=utf8mb4",
        OFF_DB_USER, OFF_DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $p;
}

function out(array $data, int $code=200): void {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    $data["_bridge"] = ["v"=>"1.6","ms"=>round((microtime(true)-BRIDGE_START)*1000,2),"ts"=>date("c"),"db"=>DB_NAME];
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function err(string $msg, int $c=400): void { out(["ok"=>false,"error"=>$msg],$c); }

// ── Disk cache for precomputed metrics ──
// A host cron re-runs the heavy queries on a schedule (hourly + wide windows
// 4x daily, ?refresh=1) so user requests are served from disk, never from a
// cold DB path. TTL is 2h: organic misses stay rare between cron runs.
define("METRIC_TTL", 7200);
// Simulation data changes as advisors complete sessions — use a 5-min TTL
// so cert counts stay fresh during an active certification window.
define("SIM_TTL", 300);

function cache_path(string $key): string {
    return sys_get_temp_dir()."/sanfer_metric_".md5($key).".json";
}
function serve_cached(string $key, int $ttl = METRIC_TTL): void {   // emits and exits on fresh HIT
    $f = cache_path($key);
    if(is_file($f) && time() - filemtime($f) < $ttl) {
        $remaining = max(0, $ttl - (time() - filemtime($f)));
        header("Content-Type: application/json; charset=utf-8");
        header("X-Bridge-Cache: HIT");
        header("Cache-Control: public, max-age=".min(60, $remaining).", stale-while-revalidate=".$ttl);
        readfile($f);
        exit;
    }
}
function out_cached(array $data, string $key, int $ttl = METRIC_TTL): void {
    $data["_bridge"] = ["v"=>"1.6","ms"=>round((microtime(true)-BRIDGE_START)*1000,2),"ts"=>date("c"),"db"=>DB_NAME];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    // Evict cache files older than 48h so daily-shifting windows don't pile up
    foreach(glob(sys_get_temp_dir()."/sanfer_metric_*.json") ?: [] as $old)
        if(filemtime($old) < time() - 172800) @unlink($old);
    @file_put_contents(cache_path($key), $json);
    http_response_code(200);
    header("Content-Type: application/json; charset=utf-8");
    header("X-Bridge-Cache: MISS");
    header("Cache-Control: public, max-age=60, stale-while-revalidate=".$ttl);
    echo $json;
    exit;
}

function strip_html(string $html): string {
    $t = strip_tags(html_entity_decode($html, ENT_QUOTES|ENT_HTML5, "UTF-8"));
    return trim(preg_replace("/\s+/"," ",$t));
}

/**
 * Extract a named section from a retroPrompt HTML blob.
 * Tries <b>Key</b>: text … up to next <b> or end; falls back to plain Key: text.
 * Preserves line breaks for model-answer bullets.
 */
function extract_rp_section(string $html, string ...$keys): string {
    // Strip <style> blocks — their text content survives strip_tags and
    // produces CSS fragments like ".uppercase {text-transform: uppercase;}" in output.
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
    foreach($keys as $key) {
        $k = preg_quote($key, '/');
        if(preg_match('/<b>'.$k.'<\/b>\s*[:：]?\s*(.*?)(?=<b>|\z)/is', $html, $m) ||
           preg_match('/'.$k.'\s*[:：]\s*(.*?)(?=<b>|<br|\z)/is', $html, $m)) {
            $t = preg_replace('/<br\s*\/?>/i', "\n", $m[1]);
            $t = strip_tags(html_entity_decode($t, ENT_QUOTES|ENT_HTML5, "UTF-8"));
            $t = preg_replace('/[ \t]+/', ' ', $t);
            return trim(preg_replace('/\n\s*/', "\n", $t));
        }
    }
    return "";
}

function extract_retroalim(string $rp): string {
    if(preg_match("/Retroalimentaci[oó]n[:：]\s*(.*?)(?:<b>|<\/b>|<br|$)/is",$rp,$m)) return strip_html($m[1]);
    if(preg_match("/[AÁ]n[aá]lisis de tu respuesta[:：]\s*(.*?)(?:<b>|<br|$)/is",$rp,$m)) return strip_html($m[1]);
    return "No aplica";
}

function extract_puntos(string $rp): ?float {
    if(preg_match("/<b>Puntaje<\/b>[:：]?\s*(\d+)/i",$rp,$m)) return (float)$m[1];
    return null;
}

function http_get_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => "",   // accept gzip if upstream supports it
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($body === false || $code !== 200) return null;
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

/**
 * Proxy a platform org endpoint, keep only the fields the dashboard reads,
 * and cache the trimmed list on disk for 10 minutes. The raw members payload
 * is ~800 KB uncompressed (incl. user tokens); trimmed + gzip it is ~30 KB.
 * If the upstream is down, serve the stale cache rather than failing.
 */
function org_proxy(string $kind, string $url, array $fields, bool $force=false, ?callable $filter=null): void {
    $cacheFile = sys_get_temp_dir()."/sanfer_org_{$kind}.json";
    $ttl = METRIC_TTL;
    if(!$force && is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        if(is_array($data)) out(["ok"=>true,"cached"=>true,"count"=>count($data),"data"=>$data]);
    }
    $resp = http_get_json($url);
    if(!is_array($resp) || !isset($resp["data"]) || !is_array($resp["data"])) {
        if(is_file($cacheFile)) {
            $data = json_decode((string)file_get_contents($cacheFile), true);
            if(is_array($data)) out(["ok"=>true,"cached"=>"stale","count"=>count($data),"data"=>$data]);
        }
        err("upstream org endpoint failed", 502);
    }
    $trim = [];
    foreach($resp["data"] as $row) {
        if(!is_array($row)) continue;
        $t = [];
        foreach($fields as $f) $t[$f] = $row[$f] ?? null;
        $trim[] = $t;
    }
    if($filter) $trim = array_values(array_filter($trim, $filter));
    @file_put_contents($cacheFile, json_encode($trim, JSON_UNESCAPED_UNICODE));
    out(["ok"=>true,"cached"=>false,"count"=>count($trim),"data"=>$trim]);
}

$in = $_SERVER["REQUEST_METHOD"]==="POST"
    ? (json_decode(file_get_contents("php://input"),true)?:$_POST)
    : $_GET;
$action = trim($in["action"]??"");

switch($action) {

case "ping":
    try { pdo()->query("SELECT 1"); out(["ok"=>true,"db"=>DB_NAME,"host"=>DB_HOST,"client"=>DB_CLIENT]); }
    catch(Exception $e){ err($e->getMessage(),503); }

case "sim.demorp6":
    $ids_csv = trim($in["ids"] ?? "");
    if($ids_csv !== "") {
        $ids = array_values(array_filter(array_map("intval", explode(",", $ids_csv))));
    } else {
        $ids_raw = $in["id"] ?? [];
        if(!is_array($ids_raw)) $ids_raw = [$ids_raw];
        $ids = array_values(array_filter(array_map("intval", $ids_raw)));
    }
    if(empty($ids)) err("ids required — pass ?ids=390,399,402,...");

    $date_from = $in["date_from"] ?? date("Y-m-d", strtotime("-60 days"));
    $date_to   = $in["date_to"]   ?? date("Y-m-d", strtotime("+1 day"));

    $cache_key = "sim|".implode(",",$ids)."|$date_from|$date_to";
    if(empty($in["refresh"])) serve_cached($cache_key, SIM_TTL);

    $ph = implode(",", array_fill(0, count($ids), "?"));
    try {
        $st = pdo()->prepare("
            SELECT e.saex_id, e.saex_useCases,
                   e.saex_rp_email      AS usuario_email,
                   e.saex_username      AS usuario_nombre,
                   e.saex_DateTime,
                   e.saex_score,
                   e.saex_scoreData,
                   e.saex_retroContents,
                   e.saex_closingContents,
                   d.closing_json
            FROM   sale_exercises e
            LEFT JOIN sale_exercises_detail d ON d.saex_id = e.saex_id
            WHERE  e.saex_useCases  IN ($ph)
              AND  e.saex_rp_client  = ?
              AND  e.saex_rp_email   IS NOT NULL
              AND  e.saex_rp_email   != ''
              AND  e.saex_rp_email   NOT LIKE '%rolplay%'
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
            ORDER  BY e.saex_DateTime DESC
        ");
        $params = array_merge($ids, [DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59"]);
        $st->execute($params);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }

    // Stream rows one at a time — fetchAll() on wide windows holds every raw
    // HTML blob in memory simultaneously and exhausts the PHP memory limit.
    $sims = [];
    while($r = $st->fetch()) {
        $sd  = $r["saex_scoreData"] ? @json_decode($r["saex_scoreData"], true) : null;
        $cal = (float)($r["saex_score"] ?? 0);
        if($cal==0 && is_array($sd)) $cal = (float)($sd["avg"]??0);
        $pts_tot = is_array($sd) ? ($sd["sum"]??0) : 0;

        // Normalize a raw verdict candidate to canonical "Si"/"No"; null = invalid.
        // Some sessions carry the unfilled template "[Cumplio_con_objetivo]" — those
        // must fall through to the next source, not count as fails.
        $norm_verdict = function(?string $raw): ?string {
            if($raw===null) return null;
            $v = mb_strtolower(strip_html($raw));
            if($v==="si" || $v==="sí" || str_starts_with($v,"si ") || str_starts_with($v,"sí ")) return "Si";
            if($v==="no" || str_starts_with($v,"no "))                                          return "No";
            return null;
        };

        $diag = null;
        if(!empty($r["saex_closingContents"]) &&
           preg_match("/<p class=\"answer\">(.*?)<\/p>/is", $r["saex_closingContents"], $dm))
            $diag = $norm_verdict($dm[1]);
        // Newer cert sessions hide the verdict in closingContents (Plan task 9);
        // the authoritative verdict lives in sale_exercises_detail.closing_json.
        if($diag===null && !empty($r["closing_json"])) {
            $cj = @json_decode($r["closing_json"], true);
            if(is_array($cj) && !empty($cj["Cumplio_con_objetivo"]))
                $diag = $norm_verdict((string)$cj["Cumplio_con_objetivo"]);
        }
        if($diag===null)
            $diag = $cal>=70 ? "Si" : "No";

        $sim = [
            "ID_Sim"            => (int)$r["saex_id"],
            "ID_Caso_de_Uso"    => (int)$r["saex_useCases"],
            "Usuario"           => $r["usuario_email"] ?? null,
            "Usuario_Nombre"    => html_entity_decode($r["usuario_nombre"]??"", ENT_QUOTES|ENT_HTML5, "UTF-8"),
            "Fecha_y_Hora"      => $r["saex_DateTime"] ?? null,
            "Calificacion"      => $cal,
            "Puntos_Totales"    => $pts_tot,
            "Diagnostico_Final" => $diag,
        ];
        for($i=1;$i<=6;$i++) {
            $sim["Pregunta_$i"]          = null;
            $sim["Respuesta_$i"]         = null;
            $sim["Retroalimentacion_$i"] = "No aplica";
            $sim["Puntos_$i"]            = "No aplica";
        }

        $retro = $r["saex_retroContents"] ? @json_decode($r["saex_retroContents"],true) : null;
        if(is_array($retro)) {
            foreach($retro as $k => $turn) {
                $n = (int)$k;
                if($n<1 || $n>6 || !is_array($turn)) continue;
                $sim["Pregunta_$n"]  = $turn["question"] ?? null;
                $sim["Respuesta_$n"] = $turn["answer"]   ?? null;
                if(!empty($turn["retroPrompt"]))
                    $sim["Retroalimentacion_$n"] = extract_retroalim($turn["retroPrompt"]);
                if(isset($turn["puntos"]) && is_numeric($turn["puntos"]))
                    $sim["Puntos_$n"] = (float)$turn["puntos"];
                elseif(!empty($turn["retroPrompt"])) {
                    $p2 = extract_puntos($turn["retroPrompt"]);
                    if($p2 !== null) $sim["Puntos_$n"] = $p2;
                }
            }
        }
        $sims[] = $sim;
    }
    out_cached(["ok"=>true,"data"=>$sims,"total_records"=>count($sims)], $cache_key, SIM_TTL);

case "sim.report":
    $sim_id = (int)($in["sim_id"] ?? 0);
    if($sim_id <= 0) err("sim_id required");
    try {
        $st = pdo()->prepare("
            SELECT e.saex_id, e.saex_useCases, e.saex_username, e.saex_rp_email,
                   e.saex_DateTime, e.saex_score, e.saex_scoreData,
                   e.saex_closingContents, e.saex_retroContents,
                   d.closing_json,
                   u.usecase_name
            FROM   sale_exercises e
            LEFT JOIN sale_exercises_detail d ON d.saex_id = e.saex_id
            LEFT JOIN usecases u ON u.id = e.saex_useCases
            WHERE  e.saex_id = ? AND e.saex_rp_client = ?
            LIMIT  1
        ");
        $st->execute([$sim_id, DB_CLIENT]);
        $r = $st->fetch();
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }
    if(!$r) err("not found", 404);

    $html  = (string)($r["saex_closingContents"] ?? "");
    $title = "";
    if(preg_match("/<h1>(.*?)<\/h1>/is", $html, $m)) $title = strip_html($m[1]);

    // The closing report is a sequence of <p class="question"> / <p class="answer"> pairs.
    // <br> separators inside answers become newlines (bullet lists in the UI).
    $clean_multiline = function(string $h): string {
        $h = preg_replace("/<br\s*\/?>/i", "\n", $h);
        $t = strip_tags(html_entity_decode($h, ENT_QUOTES|ENT_HTML5, "UTF-8"));
        $t = preg_replace("/[ \t]+/", " ", $t);
        return trim(preg_replace("/\n\s*/", "\n", $t));
    };
    $sections = [];
    if(preg_match_all("/<p class=\"question\">(.*?)<\/p>\s*<p class=\"answer\">(.*?)<\/p>/is", $html, $mm, PREG_SET_ORDER)) {
        foreach($mm as $pair) {
            $sections[] = ["q" => strip_html($pair[1]), "a" => $clean_multiline($pair[2])];
        }
    }

    // Newer cert sessions redact closingContents (Plan task 9: hide the verdict
    // from end users) — rebuild the report from sale_exercises_detail.closing_json.
    if(empty($sections) && !empty($r["closing_json"])) {
        $cj = @json_decode($r["closing_json"], true);
        if(is_array($cj)) {
            if($title==="" && !empty($cj["product"]))
                $title = "¿El médico recetará o no ".$cj["product"]."?";
            $map = [
                ["Cumplio_con_objetivo",          "¿El representante logró la prescripción del medicamento?"],
                ["siguio_pasos_venta_veredicto",  "¿Por qué?"],
                ["fortalezas",                    "¿Cuáles fortalezas demostró el representante durante la visita?"],
                ["areas_mejora",                  "¿Cuáles son las áreas de mejora del representante?"],
            ];
            foreach($map as [$key, $q]) {
                if(!empty($cj[$key]))
                    $sections[] = ["q"=>$q, "a"=>$clean_multiline((string)$cj[$key])];
            }
            if(isset($cj["puntaje_final"]) && $cj["puntaje_final"] !== "")
                $sections[] = ["q"=>"Puntaje Final", "a"=>$cj["puntaje_final"]." pts / 100 pts"];
        }
    }

    // Parse rounds 1–5 from saex_retroContents
    $retro_raw = $r["saex_retroContents"] ? @json_decode($r["saex_retroContents"], true) : null;
    $rondas = [];
    if(is_array($retro_raw)) {
        for($n = 1; $n <= 5; $n++) {
            if(!isset($retro_raw[$n]) || !is_array($retro_raw[$n])) continue;
            $turn = $retro_raw[$n];
            $rp   = (string)($turn["retroPrompt"] ?? "");
            $puntos = null;
            if(isset($turn["puntos"]) && is_numeric($turn["puntos"]))
                $puntos = (float)$turn["puntos"];
            elseif($rp) {
                $p2 = extract_puntos($rp);
                if($p2 !== null) $puntos = $p2;
            }
            $rondas[] = [
                "n"                => $n,
                "pregunta"         => $turn["question"] ?? null,
                "respuesta_rep"    => ($turn["answer"] ?? null) ?: null,
                "criterio"         => extract_rp_section($rp, "Criterio a evaluar"),
                "respuesta_modelo" => extract_rp_section($rp, "Respuesta modelo"),
                "analisis"         => extract_rp_section($rp,
                    "Análisis de tu respuesta", "Analisis de tu respuesta",
                    "Retroalimentación", "Retroalimentacion"),
                "puntos"           => $puntos,
                "max_puntos"       => 1,
            ];
        }
    }

    out(["ok"=>true, "data"=>[
        "ID_Sim"         => (int)$r["saex_id"],
        "ID_Caso_de_Uso" => (int)$r["saex_useCases"],
        "Usuario"        => $r["saex_rp_email"],
        "Usuario_Nombre" => html_entity_decode($r["saex_username"]??"", ENT_QUOTES|ENT_HTML5, "UTF-8"),
        "Fecha_y_Hora"   => $r["saex_DateTime"],
        "Calificacion"   => (float)($r["saex_score"] ?? 0),
        "Producto"       => html_entity_decode($r["usecase_name"]??"", ENT_QUOTES|ENT_HTML5, "UTF-8"),
        "Titulo"         => $title,
        "Rondas"         => $rondas,
        "Secciones"      => $sections,
    ]]);

case "org.members":
    // Active members only — mirrors the official platform WHERE clause:
    //   mb_admin NOT IN (35,103): "RolPlay Pruebas" and "Bajas Revisión" (pending termination).
    //   NOT LIKE patterns (confirmed by Silverio 2026-06-22): %test%, %demo%, %prueb%, %vacant%, %rolplay%
    //     applied to mb_user (login/email); %capacit% and %prueb% also applied to mb_fullname.
    $cacheFile = sys_get_temp_dir()."/sanfer_org_members.json";
    if(empty($in["refresh"]) && is_file($cacheFile) && time()-filemtime($cacheFile) < METRIC_TTL) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        if(is_array($data)) out(["ok"=>true,"cached"=>true,"count"=>count($data),"data"=>$data]);
    }
    try {
        $st = org_pdo()->query("
            SELECT mb_id, mb_fullname, mb_email, mb_user, mb_admin, mb_status, mb_designation, mb_idTag1
            FROM   members
            WHERE  mb_status = 1
              AND  mb_admin NOT IN (35, 103)
              AND  NOT (
                mb_user LIKE '%test%'
                OR mb_user LIKE '%demo%'
                OR mb_user LIKE '%prueb%'
                OR mb_user LIKE '%vacant%'
                OR mb_user LIKE '%rolplay%'
                OR mb_fullname LIKE '%capacit%'
                OR mb_fullname LIKE '%prueb%'
              )
            ORDER BY mb_fullname
        ");
        $data = $st->fetchAll();
    } catch(Exception $e) { err("org DB error: ".$e->getMessage(), 503); }
    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    out(["ok"=>true,"cached"=>false,"count"=>count($data),"data"=>$data]);

case "org.admins":
    // Direct DB query — rolplay_sanfer_robin — Mexico team official filter (2026-06-18)
    $cacheFile = sys_get_temp_dir()."/sanfer_org_admins.json";
    if(empty($in["refresh"]) && is_file($cacheFile) && time()-filemtime($cacheFile) < METRIC_TTL) {
        $data = json_decode((string)file_get_contents($cacheFile), true);
        if(is_array($data)) out(["ok"=>true,"cached"=>true,"count"=>count($data),"data"=>$data]);
    }
    try {
        $st = org_pdo()->query("
            SELECT rpa_id, rpa_full_name, rpa_email, rpa_profile_type, rpa_parent
            FROM   administrators
            WHERE  rpa_id NOT IN (1,28,29,97)
            ORDER BY rpa_profile_type, rpa_full_name
        ");
        $data = $st->fetchAll();
    } catch(Exception $e) { err("org DB error: ".$e->getMessage(), 503); }
    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    out(["ok"=>true,"cached"=>false,"count"=>count($data),"data"=>$data]);

case "org.certification":
    // Exact official query from queries_Sanfer.pdf — profiles_assigned JOIN members,
    // mb_admin != 97, tester/prueba/demo/vacante exclusion. No bhl_id line filter.
    // UPDATED (unified dashboard audit, verified against queries_Sanfer.pdf's
    // "Users certified by line" query): added the sales_line join so callers
    // get the REAL line name (bhl_line) per profile_id, matching the PDF's
    // own "GROUP BY prf_assigned_profile" breakdown — this was previously
    // missing here, forcing callers to fabricate a placeholder line label.
    $cacheFile = sys_get_temp_dir()."/sanfer_org_certification_v3.json";
    if(empty($in["refresh"]) && is_file($cacheFile) && time()-filemtime($cacheFile) < METRIC_TTL) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if(is_array($cached) && isset($cached['data'])) out(array_merge(["ok"=>true,"cached"=>true], $cached));
    }
    try {
        $st = official_pdo()->query("
            SELECT LOWER(m.mb_user) AS mb_user,
                   m.mb_fullname AS nombre, m.mb_reference AS empleado, m.mb_ruta AS ruta,
                   pa.prf_assigned_profile AS profile_id,
                   sales_line.bhl_line AS linea,
                   IF(pa.prf_assigned_fase1=1 AND pa.prf_assigned_fase2=1 AND pa.prf_assigned_fase3=1, 1, 0) AS finalized,
                   pa.prf_assigned_fase1 AS fase1, pa.prf_assigned_fase1_score AS fase1_score,
                   pa.prf_assigned_fase2 AS fase2, pa.prf_assigned_fase2_score AS fase2_score,
                   pa.prf_assigned_fase3 AS fase3, pa.prf_assigned_fase3_score AS fase3_score
            FROM   profiles_assigned AS pa
            JOIN   members AS m ON pa.prf_assigned_user = m.mb_id
            LEFT JOIN sales_line ON sales_line.bhl_id = pa.prf_assigned_profile
            WHERE  m.mb_admin != 97
              AND  NOT (".TESTER_EXCL.")
            ORDER BY m.mb_id ASC
        ");
        $rows = $st->fetchAll();
    } catch(Exception $e) { err("official DB error: ".$e->getMessage(), 503); }

    $certifiedCount = 0; $completedCount = 0;
    foreach($rows as $r) {
        if($r['finalized']) $certifiedCount++;
        $completedCount += (int)$r['fase1'] + (int)$r['fase2'] + (int)$r['fase3'];
    }
    $total = count($rows);
    $certStats = [
        "total"     => $total,
        "certified" => $certifiedCount,
        "completed" => $completedCount,
        "expected"  => $total * 3,
        "pct"       => $total ? (int)round($completedCount / ($total*3) * 100) : 0,
        "cert_pct"  => $total ? (int)round($certifiedCount / $total * 100) : 0,
    ];
    $payload = ["count"=>$total,"data"=>$rows,"stats"=>$certStats];
    @file_put_contents(sys_get_temp_dir()."/sanfer_cert_stats_v3.json", json_encode($certStats));
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    out(array_merge(["ok"=>true,"cached"=>false], $payload));

case "cert.stats":
    // Exact official aggregate query from queries_Sanfer.pdf.
    // No bhl_id line filter — matches rolplaysanfer.com cert page exactly.
    $statsFile = sys_get_temp_dir()."/sanfer_cert_stats_v3.json";
    if(empty($in["refresh"]) && is_file($statsFile) && time()-filemtime($statsFile) < SIM_TTL) {
        $s = json_decode((string)file_get_contents($statsFile), true);
        if(is_array($s) && isset($s["total"])) out(array_merge(["ok"=>true,"cached"=>true], $s));
    }
    try {
        $r = official_pdo()->query("
            SELECT COUNT(DISTINCT m.mb_id) AS total,
                   SUM(IF(pa.prf_assigned_fase1=1 AND pa.prf_assigned_fase2=1 AND pa.prf_assigned_fase3=1,1,0)) AS certified,
                   SUM(pa.prf_assigned_fase1) AS f1,
                   SUM(pa.prf_assigned_fase2) AS f2,
                   SUM(pa.prf_assigned_fase3) AS f3
            FROM   profiles_assigned AS pa
            JOIN   members AS m ON pa.prf_assigned_user = m.mb_id
            WHERE  m.mb_admin != 97
              AND  NOT (".TESTER_EXCL.")
        ")->fetch();
    } catch(Exception $e) { err("official DB error: ".$e->getMessage(), 503); }
    $tot  = (int)$r["total"];
    $cert = (int)$r["certified"];
    $comp = (int)$r["f1"] + (int)$r["f2"] + (int)$r["f3"];
    $stats = [
        "total"     => $tot,
        "certified" => $cert,
        "completed" => $comp,
        "expected"  => $tot * 3,
        "pct"       => $tot ? (int)round($comp / ($tot*3) * 100) : 0,
        "cert_pct"  => $tot ? (int)round($cert / $tot * 100) : 0,
    ];
    @file_put_contents($statsFile, json_encode($stats));
    out_cached(array_merge(["ok"=>true], $stats), "cert_stats_official_v3", SIM_TTL);

case "activities.demorp6":
    $ids_csv = trim($in["ids"] ?? "");
    $ids = array_values(array_filter(array_map("intval", explode(",", $ids_csv))));
    if(empty($ids)) err("ids required — pass ?ids=390,399,402,...");
    $cache_key = "act|".implode(",",$ids);
    if(empty($in["refresh"])) serve_cached($cache_key);
    $ph = implode(",", array_fill(0, count($ids), "?"));
    try {
        $st = pdo()->prepare("SELECT id, usecase_name FROM usecases WHERE id IN ($ph) ORDER BY id");
        $st->execute($ids);
        $acts = [];
        foreach($st->fetchAll() as $r) {
            $acts[] = [
                "ID_Caso_de_Uso"   => (int)$r["id"],
                "Caso_de_Uso"      => html_entity_decode($r["usecase_name"]??"", ENT_QUOTES|ENT_HTML5, "UTF-8"),
                "Actividad_Nombre" => "Certificación",
            ];
        }
        out_cached(["ok"=>true,"data"=>$acts,"total_records"=>count($acts)], $cache_key);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }

case "objections.demorp6":
    $ids_csv = trim($in["ids"] ?? "");
    $ids = array_values(array_filter(array_map("intval", explode(",", $ids_csv))));
    if(empty($ids)) err("ids required — pass ?ids=390,399,...");
    $date_from = $in["date_from"] ?? date("Y-m-d", strtotime("-60 days"));
    $date_to   = $in["date_to"]   ?? date("Y-m-d", strtotime("+1 day"));
    $cache_key = "obj2|".implode(",",$ids)."|$date_from|$date_to";
    if(empty($in["refresh"])) serve_cached($cache_key, SIM_TTL);
    $ph = implode(",", array_fill(0, count($ids), "?"));
    try {
        $st = pdo()->prepare("
            SELECT e.saex_useCases,
                   e.saex_username,
                   e.saex_rp_email,
                   d.objecion_rand,
                   d.objecion_rand_index,
                   e.saex_retroContents
            FROM   sale_exercises e
            INNER JOIN sale_exercises_detail d ON d.saex_id = e.saex_id
            WHERE  e.saex_useCases IN ($ph)
              AND  e.saex_rp_client  = ?
              AND  e.saex_rp_email   IS NOT NULL
              AND  e.saex_rp_email   != ''
              AND  e.saex_rp_email   NOT LIKE '%rolplay%'
              AND  d.objecion_rand   IS NOT NULL
              AND  d.objecion_rand   != ''
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
        ");
        $params = array_merge($ids, [DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59"]);
        $st->execute($params);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }
    $agg = [];
    while($r = $st->fetch()) {
        $uc  = (int)$r["saex_useCases"];
        $obj = trim((string)$r["objecion_rand"]);
        $idx = (int)$r["objecion_rand_index"];
        if(!$obj || !$idx) continue;
        $retro  = $r["saex_retroContents"] ? @json_decode($r["saex_retroContents"], true) : null;
        $puntos       = null;
        $user_answer  = null;
        $model_answer = null;
        if(is_array($retro) && isset($retro[$idx]) && is_array($retro[$idx])) {
            $turn = $retro[$idx];
            if(isset($turn["puntos"]) && is_numeric($turn["puntos"]))
                $puntos = (float)$turn["puntos"];
            if(!empty($turn["answer"])) {
                $ua = mb_substr(trim(strip_tags((string)$turn["answer"])), 0, 600);
                if(mb_strlen($ua) > 8) $user_answer = $ua;
            }
            if(!empty($turn["retroPrompt"]))
                $model_answer = extract_rp_section($turn["retroPrompt"], "Respuesta modelo");
        }
        $key = $uc."|".$obj;
        if(!isset($agg[$key])) $agg[$key] = [
            "usecase_id"=>$uc, "objection_text"=>$obj,
            "count"=>0, "pass_count"=>0, "scored"=>0,
            "answers"=>[], "model_answer"=>"",
        ];
        $agg[$key]["count"]++;
        if($puntos !== null) {
            $agg[$key]["scored"]++;
            if($puntos >= 1) $agg[$key]["pass_count"]++;
        }
        if($user_answer) {
            $raw_name = html_entity_decode((string)($r["saex_username"] ?? ""), ENT_QUOTES|ENT_HTML5, "UTF-8");
            $pname = trim(strip_tags($raw_name));
            if(!$pname) {
                $email = (string)($r["saex_rp_email"] ?? "");
                $pname = strpos($email,"@") !== false ? strstr($email,"@",true) : $email;
            }
            $agg[$key]["answers"][] = ["text" => $user_answer, "name" => $pname];
        }
        if($model_answer && !$agg[$key]["model_answer"]) $agg[$key]["model_answer"] = $model_answer;
    }
    $result = [];
    foreach($agg as $row) {
        // Deduplicate by first 60 chars (normalised), keep up to 3 diverse samples
        $seen = []; $top = [];
        foreach($row["answers"] as $ans) {
            $k = mb_substr(preg_replace('/\s+/', ' ', mb_strtolower($ans["text"])), 0, 60);
            if(!in_array($k, $seen, true)) { $seen[] = $k; $top[] = $ans; }
            if(count($top) >= 3) break;
        }
        $result[] = [
            "usecase_id"    => $row["usecase_id"],
            "objection_text"=> $row["objection_text"],
            "count"         => $row["count"],
            "pass_count"    => $row["pass_count"],
            "pass_rate"     => $row["scored"] ? (int)round($row["pass_count"] / $row["scored"] * 100) : 0,
            "model_answer"  => $row["model_answer"],
            "top_answers"   => $top,
        ];
    }
    usort($result, fn($a,$b) => $a["pass_rate"] <=> $b["pass_rate"]);
    out_cached(["ok"=>true,"data"=>$result,"total_records"=>count($result)], $cache_key, SIM_TTL);

case "members.raw":
    $resp = http_get_json("https://serv.aux-rolplay.com/sanfer/api/data/rolplay_sanfer_robin/members");
    if(!$resp || !isset($resp["data"])) err("upstream failed", 502);
    $all = $resp["data"];
    $first = isset($all[0]) && is_array($all[0]) ? $all[0] : [];
    $CERT_TAGS = [1,2,3,5,6,7,8,9,10,11,12,23,24,25,28];
    $tag1_freq=[]; $tag2_freq=[]; $tag3_freq=[];
    $non_cert_detail = [];   // full records for non-cert tagId1 members
    foreach($all as $m) {
        $em = strtolower($m["mb_user"] ?? "");
        $is_system = (strpos($em,"rolplay")!==false || strpos($em,"audioweb")!==false || ($m["mb_admin"]??0)>=4);
        $t1 = (int)($m["mb_idTag1"] ?? 0);
        $t2 = (int)($m["mb_idTag2"] ?? 0);
        $t3 = (int)($m["mb_idTag3"] ?? 0);
        $tag1_freq[$t1] = ($tag1_freq[$t1]??0)+1;
        $tag2_freq[$t2] = ($tag2_freq[$t2]??0)+1;
        $tag3_freq[$t3] = ($tag3_freq[$t3]??0)+1;
        if(!$is_system && !in_array($t1, $CERT_TAGS) && count($non_cert_detail)<80) {
            $non_cert_detail[] = [
                "email"=>$em,"name"=>$m["mb_fullname"]??"",
                "status"=>$m["mb_status"]??"","t1"=>$t1,"t2"=>$t2,"t3"=>$t3,
                "ruta"=>$m["mb_ruta"]??"","emp"=>$m["mb_employee_code"]??"",
                "desg"=>$m["mb_designation"]??""
            ];
        }
    }
    arsort($tag1_freq); arsort($tag2_freq); arsort($tag3_freq);
    out(["ok"=>true,"count"=>count($all),"all_keys"=>array_keys($first),
         "tag1_freq"=>$tag1_freq,"tag2_freq"=>$tag2_freq,"tag3_freq"=>$tag3_freq,
         "non_cert_detail"=>$non_cert_detail]);

case "cert.explore":
    $date_from = $in["date_from"] ?? "2026-06-08";
    $date_to   = $in["date_to"]   ?? "2026-06-22";
    try {
        $st = pdo()->prepare("
            SELECT e.saex_useCases, u.usecase_name,
                   COUNT(DISTINCT e.saex_rp_email) AS distinct_users,
                   COUNT(*) AS sessions,
                   ROUND(AVG(e.saex_score),1) AS avg_score
            FROM   sale_exercises e
            LEFT JOIN usecases u ON u.id = e.saex_useCases
            WHERE  e.saex_rp_client  = ?
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
              AND  e.saex_rp_email  IS NOT NULL
              AND  e.saex_rp_email  != ''
              AND  e.saex_rp_email  NOT LIKE '%rolplay%'
            GROUP  BY e.saex_useCases, u.usecase_name
            ORDER  BY sessions DESC
        ");
        $st->execute([DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59"]);
        out(["ok"=>true,"data"=>$st->fetchAll()]);
    } catch(Exception $e){ err($e->getMessage()); }

case "cert.users":
    // All distinct users who ran any sanfer sim in the cert window + their scores per usecase
    $date_from = $in["date_from"] ?? "2026-06-08";
    $date_to   = $in["date_to"]   ?? "2026-06-22";
    try {
        $st = pdo()->prepare("
            SELECT e.saex_rp_email AS email,
                   MAX(e.saex_username) AS name,
                   e.saex_useCases AS usecase_id,
                   u.usecase_name,
                   MAX(e.saex_score) AS best_score,
                   COUNT(*) AS attempts
            FROM   sale_exercises e
            LEFT JOIN usecases u ON u.id = e.saex_useCases
            WHERE  e.saex_rp_client  = ?
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
              AND  e.saex_rp_email  IS NOT NULL
              AND  e.saex_rp_email  != ''
              AND  e.saex_rp_email  NOT LIKE '%rolplay%'
            GROUP  BY e.saex_rp_email, e.saex_useCases
            ORDER  BY e.saex_rp_email, e.saex_useCases
        ");
        $st->execute([DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59"]);
        out(["ok"=>true,"data"=>$st->fetchAll()]);
    } catch(Exception $e){ err($e->getMessage()); }

case "cert.bestscores":
    // Like cert.users but uses scoreData.avg fallback when saex_score=0 — matches sim.demorp6 logic.
    // Fetches raw rows and aggregates in PHP because the DB lacks JSON functions.
    $date_from = $in["date_from"] ?? "2020-01-01";
    $date_to   = $in["date_to"]   ?? "2027-12-31";
    // ucids — all 44 cert sims
    $ucids_csv = "390,399,402,403,405,406,408,409,410,411,413,419,420,421,423,428,432,433,436,439,440,445,446,448,449,453,454,455,457,460,461,462,464,465,467,468,481,484,488,489,490,491,492,493";
    $ucids = array_map("intval", explode(",", $ucids_csv));
    $ph = implode(",", array_fill(0, count($ucids), "?"));
    try {
        $st = pdo()->prepare("
            SELECT e.saex_rp_email AS email,
                   e.saex_username AS name,
                   e.saex_useCases AS usecase_id,
                   u.usecase_name,
                   e.saex_score,
                   e.saex_scoreData
            FROM   sale_exercises e
            LEFT JOIN usecases u ON u.id = e.saex_useCases
            WHERE  e.saex_useCases IN ($ph)
              AND  e.saex_rp_client  = ?
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
              AND  e.saex_rp_email  IS NOT NULL
              AND  e.saex_rp_email  != ''
              AND  e.saex_rp_email  NOT LIKE '%rolplay%'
        ");
        $params = array_merge($ucids, [DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59"]);
        $st->execute($params);
    } catch(Exception $e){ err($e->getMessage()); }

    // Aggregate best score (with scoreData.avg fallback) per (email, ucid)
    $agg   = [];  // email → ucid → ['best'=>float, 'name'=>str, 'ucname'=>str, 'attempts'=>int]
    while($r = $st->fetch()) {
        $email = $r["email"];
        $ucid  = (int)$r["usecase_id"];
        $score = (float)$r["saex_score"];
        if($score == 0 && !empty($r["saex_scoreData"])) {
            $sd = @json_decode($r["saex_scoreData"], true);
            if(is_array($sd) && isset($sd["avg"]) && $sd["avg"] > 0)
                $score = (float)$sd["avg"];
        }
        if(!isset($agg[$email])) $agg[$email] = [];
        if(!isset($agg[$email][$ucid])) {
            $agg[$email][$ucid] = ["best"=>$score,"name"=>$r["name"],"ucname"=>$r["usecase_name"],"attempts"=>1];
        } else {
            $agg[$email][$ucid]["best"] = max($agg[$email][$ucid]["best"], $score);
            if($r["name"]) $agg[$email][$ucid]["name"] = $r["name"];
            $agg[$email][$ucid]["attempts"]++;
        }
    }
    $result = [];
    foreach($agg as $email => $ucids_data) {
        foreach($ucids_data as $ucid => $info) {
            $result[] = [
                "email"       => $email,
                "name"        => $info["name"],
                "usecase_id"  => $ucid,
                "usecase_name"=> $info["ucname"],
                "best_score"  => $info["best"],
                "attempts"    => $info["attempts"],
            ];
        }
    }
    usort($result, fn($a,$b) => strcmp($a["email"].$a["usecase_id"], $b["email"].$b["usecase_id"]));
    out(["ok"=>true,"data"=>$result,"total"=>count($result)]);

case "cert.clients":
    // Find all saex_rp_client values active in cert window for cert ucids
    $ucids_csv = "390,399,402,403,405,406,408,409,410,411,413,419,420,421,423,428,432,433,436,439,440,445,446,448,449,453,454,455,457,460,461,462,464,465,467,468,481,484,488,489,490,491,492,493";
    $ucids = array_map("intval", explode(",", $ucids_csv));
    $ph = implode(",", array_fill(0, count($ucids), "?"));
    $date_from = "2026-06-08"; $date_to = "2026-06-22";
    try {
        $st = pdo()->prepare("
            SELECT saex_rp_client,
                   COUNT(DISTINCT saex_rp_email) AS users,
                   COUNT(*) AS sessions
            FROM   sale_exercises
            WHERE  saex_useCases IN ($ph)
              AND  saex_DateTime >= ?
              AND  saex_DateTime <= ?
              AND  saex_rp_email IS NOT NULL
              AND  saex_rp_email != ''
              AND  saex_rp_email NOT LIKE '%rolplay%'
            GROUP  BY saex_rp_client
            ORDER  BY sessions DESC
        ");
        $params = array_merge($ucids, [$date_from." 00:00:00", $date_to." 23:59:59"]);
        $st->execute($params);
        out(["ok"=>true,"data"=>$st->fetchAll()]);
    } catch(Exception $e){ err($e->getMessage()); }

case "schema.tables":
    try {
        $tbls = pdo()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $info = [];
        foreach($tbls as $t) {
            $cnt = (int)pdo()->query("SELECT COUNT(*) FROM `".addslashes($t)."`")->fetchColumn();
            $info[] = ["table"=>$t,"rows"=>$cnt];
        }
        out(["ok"=>true,"tables"=>$info]);
    } catch(Exception $e){ err($e->getMessage()); }

case "schema.columns":
    $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', trim($in["table"]??""));
    if(!$tbl) err("table required");
    try {
        $cols   = pdo()->query("DESCRIBE `$tbl`")->fetchAll();
        $sample = pdo()->query("SELECT * FROM `$tbl` LIMIT 5")->fetchAll();
        out(["ok"=>true,"columns"=>$cols,"sample"=>$sample]);
    } catch(Exception $e){ err($e->getMessage()); }

case "saex.lookup":
    // Look up specific saex sessions by saex_id — returns email, ucid, score, scoreData avg, date
    $ids_raw = trim($in["ids"] ?? "");
    if(!$ids_raw) err("ids required — pass ?ids=509202,509203,...");
    $ids_arr = array_values(array_filter(array_map("intval", explode(",", $ids_raw))));
    if(empty($ids_arr)) err("No valid IDs parsed");
    $ph = implode(",", array_fill(0, count($ids_arr), "?"));
    try {
        $st = pdo()->prepare("
            SELECT saex_id, saex_rp_email AS email, saex_username AS nombre,
                   saex_useCases AS ucid, saex_score AS score_raw,
                   saex_scoreData AS score_data, saex_DateTime AS date
            FROM sale_exercises
            WHERE saex_id IN ($ph)
            ORDER BY saex_id
        ");
        $st->execute($ids_arr);
        $rows = [];
        while($r = $st->fetch()) {
            $sd  = $r["score_data"] ? @json_decode($r["score_data"], true) : null;
            $avg = is_array($sd) ? (float)($sd["avg"] ?? 0) : 0;
            $rows[] = [
                "saex_id"   => (int)$r["saex_id"],
                "email"     => $r["email"],
                "nombre"    => html_entity_decode($r["nombre"]??"", ENT_QUOTES|ENT_HTML5, "UTF-8"),
                "ucid"      => (int)$r["ucid"],
                "score_raw" => (float)$r["score_raw"],
                "score_avg" => $avg,
                "score"     => $r["score_raw"] > 0 ? (float)$r["score_raw"] : $avg,
                "date"      => $r["date"],
            ];
        }
        out(["ok"=>true,"sessions"=>$rows,"count"=>count($rows)]);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }

case "sim.topstats":
    // All-time best-score-per-(user,sim) aggregate for all Sanfer simulators
    // Returns aggregate KPIs + top-50 users by average best score
    $cache_key = "topstats|".DB_CLIENT;
    if(empty($in["refresh"])) serve_cached($cache_key, SIM_TTL);
    try {
        $st = pdo()->prepare("
            SELECT COUNT(*) AS total_records,
                   ROUND(AVG(best_score), 0) AS avg_best_score,
                   SUM(CASE WHEN best_score >= 80 THEN 1 ELSE 0 END) AS records_ge80,
                   COUNT(DISTINCT usecase_id) AS unique_sims,
                   COUNT(DISTINCT email) AS unique_users
            FROM (
                SELECT saex_rp_email AS email, saex_useCases AS usecase_id, MAX(saex_score) AS best_score
                FROM sale_exercises
                WHERE saex_rp_client = ?
                  AND saex_rp_email IS NOT NULL AND saex_rp_email != ''
                  AND saex_rp_email NOT LIKE '%rolplay%'
                GROUP BY saex_rp_email, saex_useCases
            ) t
        ");
        $st->execute([DB_CLIENT]);
        $agg = $st->fetch();

        $st2 = pdo()->prepare("
            SELECT email, MAX(nombre) AS nombre,
                   ROUND(AVG(best_score), 0) AS avg_best,
                   COUNT(DISTINCT usecase_id) AS sims_done,
                   SUM(CASE WHEN best_score >= 80 THEN 1 ELSE 0 END) AS sims_ge80
            FROM (
                SELECT saex_rp_email AS email,
                       MAX(saex_username) AS nombre,
                       saex_useCases AS usecase_id,
                       MAX(saex_score) AS best_score
                FROM sale_exercises
                WHERE saex_rp_client = ?
                  AND saex_rp_email IS NOT NULL AND saex_rp_email != ''
                  AND saex_rp_email NOT LIKE '%rolplay%'
                GROUP BY saex_rp_email, saex_useCases
            ) t
            GROUP BY email
            ORDER BY avg_best DESC, sims_done DESC
            LIMIT 100
        ");
        $st2->execute([DB_CLIENT]);
        $top = $st2->fetchAll();

        $top_mapped = [];
        foreach($top as $r) {
            $top_mapped[] = [
                "email"     => $r["email"],
                "nombre"    => html_entity_decode($r["nombre"] ?? "", ENT_QUOTES|ENT_HTML5, "UTF-8"),
                "avg_best"  => (int)$r["avg_best"],
                "sims_done" => (int)$r["sims_done"],
                "sims_ge80" => (int)$r["sims_ge80"],
            ];
        }

        out_cached([
            "ok"    => true,
            "stats" => [
                "total_records"  => (int)$agg["total_records"],
                "avg_best_score" => (int)$agg["avg_best_score"],
                "records_ge80"   => (int)$agg["records_ge80"],
                "unique_sims"    => (int)$agg["unique_sims"],
                "unique_users"   => (int)$agg["unique_users"],
            ],
            "top_users" => $top_mapped,
        ], $cache_key, SIM_TTL);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }

case "cert.count":
    $cache_key = "cert_count_official_v3";
    if(empty($in["refresh"])) serve_cached($cache_key, SIM_TTL);
    try {
        $r = official_pdo()->query("
            SELECT COUNT(DISTINCT m.mb_id) AS certified
            FROM   profiles_assigned AS pa
            JOIN   members AS m ON pa.prf_assigned_user = m.mb_id
            WHERE  m.mb_admin != 97
              AND  NOT (".TESTER_EXCL.")
              AND  pa.prf_assigned_fase1 = 1
              AND  pa.prf_assigned_fase2 = 1
              AND  pa.prf_assigned_fase3 = 1
        ")->fetch();
    } catch(Exception $e) { err("official DB error: ".$e->getMessage(), 503); }
    out_cached(["ok"=>true,"certified"=>(int)$r["certified"]], $cache_key, SIM_TTL);

case "cert.sessions":
    // NEW — added for the Rolplay unified dashboard. Raw per-session rows for
    // ANY usecase active in the requested window (no hardcoded ucid list
    // required, unlike sim.demorp6). Powers trends/best-performers/results
    // views without needing a bespoke aggregate action per view.
    $date_from = $in["date_from"] ?? date("Y-m-d", strtotime("-30 days"));
    $date_to   = $in["date_to"]   ?? date("Y-m-d", strtotime("+1 day"));
    $limit     = min((int)($in["limit"] ?? 5000), 20000);
    $cache_key = "certsess|$date_from|$date_to|$limit";
    if(empty($in["refresh"])) serve_cached($cache_key, SIM_TTL);
    try {
        $st = pdo()->prepare("
            SELECT e.saex_id, e.saex_useCases, u.usecase_name,
                   e.saex_rp_email AS email, e.saex_username AS name,
                   e.saex_DateTime, e.saex_score
            FROM   sale_exercises e
            LEFT JOIN usecases u ON u.id = e.saex_useCases
            WHERE  e.saex_rp_client  = ?
              AND  e.saex_DateTime  >= ?
              AND  e.saex_DateTime  <= ?
              AND  e.saex_rp_email  IS NOT NULL
              AND  e.saex_rp_email  != ''
              AND  e.saex_rp_email  NOT LIKE '%rolplay%'
            ORDER  BY e.saex_DateTime DESC
            LIMIT  ?
        ");
        $st->execute([DB_CLIENT, $date_from." 00:00:00", $date_to." 23:59:59", $limit]);
        $rows = [];
        while($r = $st->fetch()) {
            $rows[] = [
                "id"           => (int)$r["saex_id"],
                "usecase_id"   => (int)$r["saex_useCases"],
                "usecase_name" => html_entity_decode($r["usecase_name"] ?? "", ENT_QUOTES|ENT_HTML5, "UTF-8"),
                "email"        => $r["email"],
                "name"         => html_entity_decode($r["name"] ?? "", ENT_QUOTES|ENT_HTML5, "UTF-8"),
                "date"         => $r["saex_DateTime"],
                "score"        => (float)$r["saex_score"],
            ];
        }
        out_cached(["ok"=>true,"data"=>$rows,"total_records"=>count($rows)], $cache_key, SIM_TTL);
    } catch(Exception $e) { err("DB error: ".$e->getMessage(), 503); }

default:
    out(["ok"=>true,"bridge"=>"Rolplay Sanfer Bridge v1.6","db"=>DB_NAME,"client"=>DB_CLIENT,"actions"=>["ping","sim.demorp6","sim.report","objections.demorp6","activities.demorp6","org.members","org.admins","cert.count","cert.sessions"]]);
}
