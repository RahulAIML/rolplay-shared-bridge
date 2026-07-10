<?php
/**
 * Apotex tenant — business logic verbatim from the original single-tenant
 * apotex_bridge.php (v3.0). DB credentials are NOT defined here — the shared
 * front controller (../index.php) defines DB_HOST/DB_USER/DB_PASS/DB_NAME
 * and DB2_HOST/DB2_USER/DB2_PASS/DB2_NAME as PHP constants (read from
 * APOTEX_DB_* / APOTEX_DB2_* environment variables) BEFORE requiring this
 * file. The getenv()?:CONSTANT calls below are original code, unchanged —
 * getenv() returns false so they fall through to the constants the front
 * controller just defined, instead of a hardcoded secret.
 *
 * ACTIONS
 *   ping
 *   schema.tables | schema.columns | schema.full
 *   kpi.overview | kpi.activity_summary | kpi.leaderboard
 *   kpi.score_trend | kpi.score_distribution | kpi.completion_rate
 *   kpi.user_detail | kpi.sessions | kpi.login_activity
 *   list.activities | list.members | list.admins | list.tags | list.assignments
 */
declare(strict_types=1);
@ini_set('display_errors','0');
error_reporting(0);

// ── Config — DB1: rolplay_apotex_robin (platform) — non-secret ────
define('DB_PORT',3306);
define('DB_COLL','utf8mb4');

// ── Config — DB2: roleplay_demorp6 (Set 3 exercises) — non-secret ──
define('DB2_PORT',3306);

$host = getenv('DB_HOST')?:DB_HOST;
$user = getenv('DB_USER')?:DB_USER;
$pass = getenv('DB_PASS')?:DB_PASS;
$name = getenv('DB_NAME')?:DB_NAME;

// ── CORS ──────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

// ── PDO — DB1 (rolplay_apotex_robin) ──────────────────────
function pdo(): PDO {
    static $p=null;
    if($p) return $p;
    global $host,$user,$pass,$name;
    $p=new PDO("mysql:host=$host;port=".DB_PORT.";dbname=$name;charset=".DB_COLL,
        $user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                     PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                     PDO::ATTR_EMULATE_PREPARES=>false]);
    return $p;
}

// ── PDO — DB2 (roleplay_demorp6 — Set 3 exercises) ────────
function pdo2(): PDO {
    static $p=null;
    if($p) return $p;
    $h  = getenv('DB2_HOST') ?: DB2_HOST;
    $u  = getenv('DB2_USER') ?: DB2_USER;
    $pw = getenv('DB2_PASS') ?: DB2_PASS;
    $db = getenv('DB2_NAME') ?: DB2_NAME;
    $p=new PDO("mysql:host=$h;port=".DB2_PORT.";dbname=$db;charset=utf8mb4",
        $u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false]);
    return $p;
}

// ── Helpers ───────────────────────────────────────────────
function q(string $sql, array $b=[]): array {
    $st=pdo()->prepare($sql); $st->execute($b); return $st->fetchAll();
}
function q1(string $sql, array $b=[]): ?array {
    $r=q($sql,$b); return $r[0]??null;
}
function out(array $data, int $code=200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $data['_bridge']=['v'=>'3.0','ms'=>round((microtime(true)-BRIDGE_START)*1000,2),'ts'=>date('c')];
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function err(string $msg, int $c=400): void { out(['ok'=>false,'error'=>$msg],$c); }

// ── Input ─────────────────────────────────────────────────
$in = $_SERVER['REQUEST_METHOD']==='POST'
    ? (json_decode(file_get_contents('php://input'),true)?:$_POST)
    : $_GET;
$action = trim($in['action']??'');

// ── Date filter helper ────────────────────────────────────
function date_filter(array $in, string $col='svc.simv_callback_datetime'): array {
    $w=[]; $b=[];
    if(!empty($in['date_from'])){$w[]="$col >= ?";$b[]=$in['date_from'];}
    if(!empty($in['date_to']  )){$w[]="$col <= ?";$b[]=$in['date_to'];}
    return [$w?'AND '.implode(' AND ',$w):'', $b];
}

// ── Router ────────────────────────────────────────────────
switch($action){

// ────────────────────────────────────────────────────────
case 'ping':
    try{ pdo()->query('SELECT 1'); out(['ok'=>true,'db'=>DB_NAME,'host'=>DB_HOST]); }
    catch(Exception $e){ err($e->getMessage(),503); }

// ════════════════════════════════════════════════════════
// SCHEMA
// ════════════════════════════════════════════════════════
case 'schema.tables':
    out(['ok'=>true,'tables'=>q(
        "SELECT TABLE_NAME,TABLE_ROWS,
                ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,3) AS size_mb,
                CREATE_TIME, TABLE_COMMENT
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=? ORDER BY TABLE_ROWS DESC",[$name])]);

case 'schema.columns':
    $tbl=$in['table']??''; if(!$tbl) err('table required');
    out(['ok'=>true,'table'=>$tbl,'columns'=>q(
        "SELECT COLUMN_NAME,ORDINAL_POSITION,DATA_TYPE,COLUMN_TYPE,
                IS_NULLABLE,COLUMN_KEY,COLUMN_DEFAULT,EXTRA,COLUMN_COMMENT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
         ORDER BY ORDINAL_POSITION",[$name,$tbl])]);

case 'schema.full':
    $tables=q("SELECT TABLE_NAME,TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_ROWS DESC",[$name]);
    $cols  =q("SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION",[$name]);
    $byTbl=[];
    foreach($cols as $c) $byTbl[$c['TABLE_NAME']][]=$c;
    foreach($tables as &$t) $t['columns']=$byTbl[$t['TABLE_NAME']]??[];
    out(['ok'=>true,'database'=>$name,'tables'=>$tables]);

// ════════════════════════════════════════════════════════
// KPI – OVERVIEW
// ════════════════════════════════════════════════════════
case 'kpi.overview':
    [$dw,$db]=date_filter($in);
    $act=$in['activity_id']??'';
    $aw=''; $ab=[];
    if($act){$aw='AND svc.simv_callback_rolplay=?';$ab=[$act];}

    $stats=q1("
        SELECT COUNT(*)                                          AS total_sessions,
               COUNT(DISTINCT svc.simv_callback_user)           AS unique_users,
               ROUND(AVG(svc.simv_callback_score),2)            AS avg_score,
               ROUND(MIN(svc.simv_callback_score),2)            AS min_score,
               ROUND(MAX(svc.simv_callback_score),2)            AS max_score,
               SUM(svc.simv_callback_score >= 70)               AS sessions_pass,
               SUM(svc.simv_callback_score  < 70)               AS sessions_fail,
               COUNT(DISTINCT svc.simv_callback_rolplay)        AS activities_used
        FROM simulador_ventas_callback svc
        WHERE 1=1 $dw $aw",array_merge($db,$ab));

    $members =q1("SELECT COUNT(*) AS c FROM members")['c']??0;
    $admins  =q1("SELECT COUNT(*) AS c FROM administrators")['c']??0;
    $assigned=q1("SELECT COUNT(DISTINCT asim_user) AS c FROM assign_simuladors_users")['c']??0;
    $logins  =q1("SELECT COUNT(*) AS c FROM login_logs")['c']??0;
    $activities=q1("SELECT COUNT(*) AS c FROM simulador_ventas WHERE simv_status=1")['c']??0;

    $total=(int)($stats['total_sessions']??0);
    $pass =(int)($stats['sessions_pass'] ??0);
    $stats['pass_rate_pct']=$total>0?round($pass/$total*100,2):0;
    $stats['total_members']    =(int)$members;
    $stats['total_admins']     =(int)$admins;
    $stats['assigned_users']   =(int)$assigned;
    $stats['total_logins']     =(int)$logins;
    $stats['active_activities']=(int)$activities;
    out(['ok'=>true,'overview'=>$stats]);

// ════════════════════════════════════════════════════════
// KPI – ACTIVITY SUMMARY
// ════════════════════════════════════════════════════════
case 'kpi.activity_summary':
    // FIXED (unified dashboard audit): the original query LEFT JOINed BOTH
    // simulador_ventas_callback and assign_simuladors_users directly off
    // sv.simv_id in one SELECT — two independent one-to-many joins from the
    // same row cross-multiply (fan out), so COUNT(svc.simv_callback_id) was
    // counting callback-rows × assigned-users instead of true session count
    // (confirmed: activity 8 showed "sessions": 2352 here vs 84 via
    // kpi.overview?activity_id=8 — off by exactly assigned_users=28).
    // COUNT(DISTINCT ...) fixes it; AVG/MIN/MAX were already correct since
    // duplicating identical rows doesn't change their aggregate.
    [$dw,$db]=date_filter($in);
    $rows=q("
        SELECT sv.simv_id                                                    AS activity_id,
               sv.simv_title                                                 AS activity_name,
               sv.simv_type                                                  AS activity_type,
               sv.simv_desc                                                  AS description,
               sv.simv_main_activity                                         AS slug,
               sv.simv_case                                                  AS usecase_id,
               COUNT(DISTINCT svc.simv_callback_id)                          AS sessions,
               COUNT(DISTINCT svc.simv_callback_user)                        AS unique_users,
               ROUND(AVG(svc.simv_callback_score),2)                        AS avg_score,
               ROUND(MIN(svc.simv_callback_score),2)                        AS min_score,
               ROUND(MAX(svc.simv_callback_score),2)                        AS max_score,
               COUNT(DISTINCT CASE WHEN svc.simv_callback_score >= 70
                                    THEN svc.simv_callback_id END)           AS sessions_pass,
               COUNT(DISTINCT asu.asim_user)                                 AS assigned_users
        FROM simulador_ventas sv
        LEFT JOIN simulador_ventas_callback svc
               ON svc.simv_callback_rolplay = sv.simv_id $dw
        LEFT JOIN assign_simuladors_users asu
               ON asu.asim_simulator = sv.simv_id
        GROUP BY sv.simv_id
        ORDER BY sessions DESC",$db);

    foreach($rows as &$r){
        $s=(int)($r['sessions']??0);
        $p=(int)($r['sessions_pass']??0);
        $r['pass_rate_pct']=$s>0?round($p/$s*100,2):0;
    }
    out(['ok'=>true,'activities'=>$rows]);

// ════════════════════════════════════════════════════════
// KPI – LEADERBOARD
// ════════════════════════════════════════════════════════
case 'kpi.leaderboard':
    [$dw,$db]=date_filter($in);
    $limit=min((int)($in['limit']??50),500);
    $act=$in['activity_id']??'';
    $aw=''; $ab=[];
    if($act){$aw='AND svc.simv_callback_rolplay=?';$ab=[$act];}

    $rows=q("
        SELECT m.mb_id,
               m.mb_fullname                                     AS name,
               m.mb_email                                        AS email,
               m.mb_branch                                       AS branch,
               m.mb_line                                         AS line,
               COUNT(svc.simv_callback_id)                       AS sessions,
               ROUND(AVG(svc.simv_callback_score),2)             AS avg_score,
               ROUND(MAX(svc.simv_callback_score),2)             AS best_score,
               SUM(svc.simv_callback_score >= 70)                AS sessions_pass,
               MAX(svc.simv_callback_datetime)                   AS last_session
        FROM members m
        JOIN simulador_ventas_callback svc ON svc.simv_callback_user=m.mb_id
        WHERE 1=1 $dw $aw
        GROUP BY m.mb_id
        ORDER BY avg_score DESC
        LIMIT ?",array_merge($db,$ab,[$limit]));

    foreach($rows as &$r){
        $s=(int)$r['sessions']; $p=(int)$r['sessions_pass'];
        $r['pass_rate_pct']=$s>0?round($p/$s*100,2):0;
    }
    out(['ok'=>true,'leaderboard'=>$rows]);

// ════════════════════════════════════════════════════════
// KPI – SCORE TREND
// ════════════════════════════════════════════════════════
case 'kpi.score_trend':
    [$dw,$db]=date_filter($in);
    $gran=in_array($in['granularity']??'month',['day','week','month','year'])
          ?($in['granularity']??'month'):'month';
    $fmt=['day'=>'%Y-%m-%d','week'=>'%x-W%v','month'=>'%Y-%m','year'=>'%Y'][$gran];
    $act=$in['activity_id']??''; $aw=''; $ab=[];
    if($act){$aw='AND svc.simv_callback_rolplay=?';$ab=[$act];}

    $rows=q("
        SELECT DATE_FORMAT(svc.simv_callback_datetime,'$fmt') AS period,
               COUNT(*)                                        AS sessions,
               COUNT(DISTINCT svc.simv_callback_user)         AS unique_users,
               ROUND(AVG(svc.simv_callback_score),2)          AS avg_score,
               SUM(svc.simv_callback_score>=70)               AS sessions_pass
        FROM simulador_ventas_callback svc
        WHERE 1=1 $dw $aw
        GROUP BY period ORDER BY period ASC",array_merge($db,$ab));

    out(['ok'=>true,'granularity'=>$gran,'trend'=>$rows]);

// ════════════════════════════════════════════════════════
// KPI – SCORE DISTRIBUTION
// ════════════════════════════════════════════════════════
case 'kpi.score_distribution':
    [$dw,$db]=date_filter($in);
    $act=$in['activity_id']??''; $aw=''; $ab=[];
    if($act){$aw='AND simv_callback_rolplay=?';$ab=[$act];}

    $scores=q("SELECT simv_callback_score AS s FROM simulador_ventas_callback WHERE 1=1 $dw $aw",
               array_merge($db,$ab));
    $scores=array_column($scores,'s');
    $buckets=['0-30'=>0,'31-50'=>0,'51-69'=>0,'70-84'=>0,'85-100'=>0];
    foreach($scores as $s){
        $s=(float)$s;
        if($s<=30)      $buckets['0-30']++;
        elseif($s<=50)  $buckets['31-50']++;
        elseif($s<=69)  $buckets['51-69']++;
        elseif($s<=84)  $buckets['70-84']++;
        else            $buckets['85-100']++;
    }
    $total=count($scores);
    $dist=[];
    foreach($buckets as $range=>$count)
        $dist[]=['range'=>$range,'count'=>$count,'pct'=>$total>0?round($count/$total*100,2):0];
    out(['ok'=>true,'total'=>$total,'distribution'=>$dist]);

// ════════════════════════════════════════════════════════
// KPI – COMPLETION RATE
// ════════════════════════════════════════════════════════
case 'kpi.completion_rate':
    $rows=q("
        SELECT sv.simv_id                                            AS activity_id,
               sv.simv_title                                         AS activity_name,
               sv.simv_type                                          AS activity_type,
               COUNT(DISTINCT asu.asim_user)                         AS assigned,
               COUNT(DISTINCT svc.simv_callback_user)                AS completed
        FROM simulador_ventas sv
        LEFT JOIN assign_simuladors_users asu ON asu.asim_simulator=sv.simv_id
        LEFT JOIN simulador_ventas_callback svc ON svc.simv_callback_rolplay=sv.simv_id
                  AND svc.simv_callback_user=asu.asim_user
        GROUP BY sv.simv_id ORDER BY sv.simv_id");
    foreach($rows as &$r){
        $a=(int)$r['assigned'];$c=(int)$r['completed'];
        $r['completion_pct']=$a>0?round($c/$a*100,2):0;
        $r['not_started']=$a-$c;
    }
    out(['ok'=>true,'completion'=>$rows]);

// ════════════════════════════════════════════════════════
// KPI – USER DETAIL
// ════════════════════════════════════════════════════════
case 'kpi.user_detail':
    $uid=$in['user_id']??''; $email=$in['email']??'';
    if(!$uid&&!$email) err('user_id or email required');
    $cond=$uid?'m.mb_id=?':'m.mb_email=?';
    $val=$uid?:(int)0;
    if($email) $val=$email;

    $member=q1("SELECT * FROM members m WHERE $cond",[$val]);
    if(!$member) err('User not found',404);
    $mid=$member['mb_id'];

    $summary=q1("
        SELECT COUNT(*)                               AS sessions,
               ROUND(AVG(simv_callback_score),2)      AS avg_score,
               ROUND(MAX(simv_callback_score),2)      AS best_score,
               ROUND(MIN(simv_callback_score),2)      AS worst_score,
               SUM(simv_callback_score>=70)           AS sessions_pass,
               MIN(simv_callback_datetime)            AS first_session,
               MAX(simv_callback_datetime)            AS last_session
        FROM simulador_ventas_callback
        WHERE simv_callback_user=?",[$mid]);

    $by_activity=q("
        SELECT sv.simv_title,sv.simv_type,
               COUNT(*)                               AS attempts,
               ROUND(AVG(svc.simv_callback_score),2)  AS avg_score,
               ROUND(MAX(svc.simv_callback_score),2)  AS best_score,
               MAX(svc.simv_callback_datetime)        AS last_attempt
        FROM simulador_ventas_callback svc
        JOIN simulador_ventas sv ON sv.simv_id=svc.simv_callback_rolplay
        WHERE svc.simv_callback_user=?
        GROUP BY sv.simv_id ORDER BY avg_score DESC",[$mid]);

    $sessions=q("
        SELECT svc.simv_callback_id,svc.simv_callback_datetime,
               sv.simv_title,sv.simv_type,
               svc.simv_callback_score,
               LEFT(svc.simv_callback_feedback,500) AS feedback_preview
        FROM simulador_ventas_callback svc
        JOIN simulador_ventas sv ON sv.simv_id=svc.simv_callback_rolplay
        WHERE svc.simv_callback_user=?
        ORDER BY svc.simv_callback_datetime DESC LIMIT 100",[$mid]);

    $assigned=q("
        SELECT sv.simv_title,sv.simv_type,asu.asim_datetime
        FROM assign_simuladors_users asu
        JOIN simulador_ventas sv ON sv.simv_id=asu.asim_simulator
        WHERE asu.asim_user=?",[$mid]);

    $logins=q("SELECT log_datetime,log_type_user,log_user_agent
               FROM login_logs WHERE log_user=? ORDER BY log_datetime DESC LIMIT 20",[$mid]);

    out(['ok'=>true,'member'=>$member,'summary'=>$summary,
         'by_activity'=>$by_activity,'sessions'=>$sessions,
         'assigned'=>$assigned,'recent_logins'=>$logins]);

// ════════════════════════════════════════════════════════
// KPI – SESSIONS LIST (paginated)
// ════════════════════════════════════════════════════════
case 'kpi.sessions':
    [$dw,$db]=date_filter($in);
    $limit=min((int)($in['limit']??100),1000);
    $offset=max((int)($in['offset']??0),0);
    $act=$in['activity_id']??''; $aw=''; $ab=[];
    if($act){$aw='AND svc.simv_callback_rolplay=?';$ab=[$act];}
    $search=$in['search']??''; $sw=''; $sb=[];
    if($search){$sw='AND (m.mb_fullname LIKE ? OR m.mb_email LIKE ?)';
                $sb=['%'.$search.'%','%'.$search.'%'];}

    $count=q1("SELECT COUNT(*) AS c
               FROM simulador_ventas_callback svc
               JOIN members m ON m.mb_id=svc.simv_callback_user
               WHERE 1=1 $dw $aw $sw",
               array_merge($db,$ab,$sb))['c']??0;

    $rows=q("
        SELECT svc.simv_callback_id                     AS id,
               svc.simv_callback_datetime               AS fecha,
               m.mb_id,m.mb_fullname                   AS nombre,
               m.mb_email,m.mb_branch,m.mb_line,
               sv.simv_id                               AS activity_id,
               sv.simv_title                            AS actividad,
               sv.simv_type                             AS tipo,
               sv.simv_case                             AS usecase_id,
               svc.simv_callback_score                  AS score,
               svc.simv_callback_saex                   AS saex_ref
        FROM simulador_ventas_callback svc
        JOIN members m ON m.mb_id=svc.simv_callback_user
        JOIN simulador_ventas sv ON sv.simv_id=svc.simv_callback_rolplay
        WHERE 1=1 $dw $aw $sw
        ORDER BY svc.simv_callback_datetime DESC
        LIMIT ? OFFSET ?",
        array_merge($db,$ab,$sb,[$limit,$offset]));

    out(['ok'=>true,'total'=>(int)$count,'limit'=>$limit,'offset'=>$offset,'sessions'=>$rows]);

// ════════════════════════════════════════════════════════
// KPI – LOGIN ACTIVITY
// ════════════════════════════════════════════════════════
case 'kpi.login_activity':
    [$dw,$db]=date_filter($in,'ll.log_datetime');
    $rows=q("
        SELECT DATE_FORMAT(ll.log_datetime,'%Y-%m') AS month,
               COUNT(*)                              AS logins,
               COUNT(DISTINCT ll.log_user)           AS unique_users,
               ll.log_type_user                      AS user_type
        FROM login_logs ll
        WHERE 1=1 $dw
        GROUP BY month,ll.log_type_user
        ORDER BY month DESC",$db);
    $total=q1("SELECT COUNT(*) AS c, COUNT(DISTINCT log_user) AS u FROM login_logs");
    out(['ok'=>true,'summary'=>$total,'monthly'=>$rows]);

// ════════════════════════════════════════════════════════
// LIST ENDPOINTS
// ════════════════════════════════════════════════════════
case 'list.activities':
    // FIXED (unified dashboard audit): same double-JOIN fan-out as
    // kpi.activity_summary — COUNT(DISTINCT ...) required.
    out(['ok'=>true,'activities'=>q(
        "SELECT sv.*,
                COUNT(DISTINCT svc.simv_callback_id)   AS total_sessions,
                COUNT(DISTINCT asu.asim_user)           AS assigned_users
         FROM simulador_ventas sv
         LEFT JOIN simulador_ventas_callback svc ON svc.simv_callback_rolplay=sv.simv_id
         LEFT JOIN assign_simuladors_users asu    ON asu.asim_simulator=sv.simv_id
         GROUP BY sv.simv_id ORDER BY sv.simv_id")]);

case 'list.members':
    $search=$in['search']??'';
    $sw=''; $sb=[];
    if($search){$sw='WHERE mb_fullname LIKE ? OR mb_email LIKE ?';
                $sb=['%'.$search.'%','%'.$search.'%'];}
    out(['ok'=>true,'count'=>(int)(q1("SELECT COUNT(*) AS c FROM members $sw",$sb)['c']??0),
         'members'=>q("SELECT mb_id,mb_fullname,mb_email,mb_admin,mb_branch,mb_city,
                              mb_state,mb_line,mb_designation,mb_employee_code,
                              mb_date_create,mb_last_login,mb_status,mb_idTag1,mb_idTag2,mb_idTag3
                       FROM members $sw ORDER BY mb_fullname",$sb)]);

case 'list.admins':
    out(['ok'=>true,'count'=>(int)(q1("SELECT COUNT(*) AS c FROM administrators")['c']??0),
         'admins'=>q("SELECT rpa_id,rpa_full_name,rpa_email,rpa_profile_type,rpa_company,
                             rpa_sede,rpa_enabled_stt,rpa_enabled_ae,rpa_mod_admin,
                             rpa_mod_creator,rpa_is_demo,rpa_create_date
                      FROM administrators ORDER BY rpa_full_name")]);

case 'list.tags':
    out(['ok'=>true,
         'tag1'=>q("SELECT t.*,COUNT(m.mb_id) AS member_count
                    FROM tag1 t LEFT JOIN members m ON m.mb_idTag1=t.id
                    GROUP BY t.id"),
         'tag2'=>q("SELECT * FROM tag2")]);

case 'list.assignments':
    [$limit,$offset]=[min((int)($in['limit']??200),1000),max((int)($in['offset']??0),0)];
    out(['ok'=>true,'assignments'=>q(
        "SELECT asu.asim_id,asu.asim_datetime,
                m.mb_id,m.mb_fullname,m.mb_email,
                sv.simv_id,sv.simv_title,sv.simv_type,
                svc.simv_callback_score   AS score,
                svc.simv_callback_datetime AS completed_at,
                CASE WHEN svc.simv_callback_id IS NOT NULL THEN 'completed' ELSE 'pending' END AS status
         FROM assign_simuladors_users asu
         JOIN members m ON m.mb_id=asu.asim_user
         JOIN simulador_ventas sv ON sv.simv_id=asu.asim_simulator
         LEFT JOIN simulador_ventas_callback svc
                ON svc.simv_callback_rolplay=asu.asim_simulator
               AND svc.simv_callback_user=asu.asim_user
         ORDER BY asu.asim_datetime DESC LIMIT ? OFFSET ?",[$limit,$offset])]);

case 'list.supervisors':
    out(['ok'=>true,'supervisors'=>q(
        "SELECT m.mb_id,m.mb_fullname,m.mb_email,
                COUNT(DISTINCT csa.csa_admin)  AS admins_linked,
                COUNT(DISTINCT svc.simv_callback_id) AS sessions_supervised
         FROM members m
         LEFT JOIN control_supervisor_admin csa ON csa.csa_supervisor=m.mb_id
         LEFT JOIN simulador_ventas_callback svc ON svc.simv_callback_user=m.mb_id
         GROUP BY m.mb_id
         HAVING admins_linked>0 OR sessions_supervised>0
         ORDER BY m.mb_fullname")]);

// ════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════
// KPI – SESSION DETAIL (single session, full feedback)
// NEW — added for the Rolplay unified dashboard drilldown page.
// Same shape as kpi.sessions but for exactly one simv_callback_id,
// with the FULL feedback text (kpi.sessions truncates to 500 chars).
// ════════════════════════════════════════════════════════
case 'kpi.session_detail':
    $id = (int)($in['id'] ?? 0);
    if (!$id) err('id required');
    $row = q1("
        SELECT svc.simv_callback_id              AS id,
               svc.simv_callback_datetime         AS fecha,
               m.mb_id, m.mb_fullname              AS nombre, m.mb_email,
               sv.simv_id                          AS activity_id,
               sv.simv_title                       AS actividad,
               sv.simv_type                        AS tipo,
               sv.simv_case                         AS usecase_id,
               svc.simv_callback_score             AS score,
               svc.simv_callback_feedback          AS feedback
        FROM   simulador_ventas_callback svc
        JOIN   members m ON m.mb_id = svc.simv_callback_user
        JOIN   simulador_ventas sv ON sv.simv_id = svc.simv_callback_rolplay
        WHERE  svc.simv_callback_id = ?
        LIMIT 1", [$id]);
    if (!$row) err('not found', 404);
    out(['ok' => true, 'session' => $row]);

// ════════════════════════════════════════════════════════
// COACH MAESTRO SCORE ENRICHMENT
// Returns saex_id → score map for Coach Maestro exercises
// (bridge activity IDs 8=Periamid/174, 9=Parkinson/175, 10=Neristren/176)
// Replaces 3 separate kpi.sessions calls with ONE fast query
// ════════════════════════════════════════════════════════
case 'kpi.coach_scores':
    // Simple: single query, no complex join, returns just what client needs
    $rows = q("
        SELECT svc.simv_callback_saex   AS saex_id,
               svc.simv_callback_score  AS score,
               sv.simv_case             AS usecase_id
        FROM   simulador_ventas_callback svc
        JOIN   simulador_ventas sv ON sv.simv_id = svc.simv_callback_rolplay
        WHERE  svc.simv_callback_rolplay IN (8, 9, 10)
          AND  svc.simv_callback_saex   IS NOT NULL
          AND  svc.simv_callback_score  >  0
    ");
    // Build compact map: { saex_id: score }
    $map = [];
    foreach ($rows as $r) {
        if ($r['saex_id']) $map[(int)$r['saex_id']] = (float)$r['score'];
    }
    out(['ok' => true, 'scores' => $map, 'count' => count($map)]);

// ════════════════════════════════════════════════════════
// SIM EXTRACTOR — roleplay_demorp6 (Exercise Set 3)
// IDs: 470=Arabrixen, 471=Apodrolen D, 475=Cluminol,
//      476=Divertex, 485=Periamid
// Returns Simulation-shaped rows identical to rol_play_sim_extractor
// ════════════════════════════════════════════════════════
case 'sim.demorp6':
    // Accept three formats:
    //   ?ids=470,471,475,476,485          (comma-separated — preferred, PHP-safe)
    //   ?id[]=470&id[]=471                (PHP array syntax)
    //   ?id=470&id=471  ← PHP keeps last  (legacy — use ids= instead)
    $ids_csv = trim($in['ids'] ?? '');
    if ($ids_csv !== '') {
        $ids = array_values(array_filter(array_map('intval', explode(',', $ids_csv))));
    } else {
        $ids_raw = $in['id'] ?? [];
        if (!is_array($ids_raw)) $ids_raw = [$ids_raw];
        $ids = array_values(array_filter(array_map('intval', $ids_raw)));
    }
    if (empty($ids)) err('ids required — pass ?ids=470,471,475,476,485');

    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = pdo2()->prepare("
            SELECT saex_id             AS ID_Sim,
                   saex_useCases       AS ID_Caso_de_Uso,
                   saex_useCasesTitle  AS Caso_de_Uso_Title,
                   saex_rp_email       AS Usuario,
                   saex_username       AS Usuario_Nombre,
                   saex_DateTime       AS Fecha_y_Hora,
                   saex_score          AS Calificacion,
                   saex_iterations     AS num_iterations,
                   saex_scoreData      AS score_json,
                   saex_retroContents  AS retro_json,
                   saex_closingContents AS closing_json
            FROM   sale_exercises
            WHERE  saex_useCases IN ($ph)
              AND  saex_rp_client = 'apotex'
              AND  saex_rp_email  IS NOT NULL
              AND  saex_rp_email  != ''
            ORDER  BY saex_DateTime DESC
        ");
        $st->execute($ids);
        $raw = $st->fetchAll();
    } catch (Exception $e) {
        err('DB2 connection failed — check DB2_HOST/DB2_USER/DB2_PASS in .env: ' . $e->getMessage(), 503);
    }

    $sims = [];
    foreach ($raw as $r) {
        $sim = [
            'ID_Sim'           => (int)$r['ID_Sim'],
            'ID_Caso_de_Uso'   => (int)$r['ID_Caso_de_Uso'],
            'Usuario'          => $r['Usuario']        ?? null,
            'Usuario_Nombre'   => html_entity_decode($r['Usuario_Nombre'] ?? '', ENT_QUOTES|ENT_HTML5, 'UTF-8'),
            'Fecha_y_Hora'     => $r['Fecha_y_Hora']   ?? null,
            'Calificacion'     => (float)($r['Calificacion'] ?? 0),
            'Puntos_Totales'   => 0,
            'Diagnostico_Final'=> null,
        ];
        // Initialise all 6 round slots as null / No aplica
        for ($i = 1; $i <= 6; $i++) {
            $sim["Pregunta_$i"]          = null;
            $sim["Respuesta_$i"]         = null;
            $sim["Retroalimentacion_$i"] = null;
            $sim["Puntos_$i"]            = 'No aplica';
        }
        // ── Parse retro JSON (conversation turns) ──────────────
        $retro = $r['retro_json'] ? @json_decode($r['retro_json'], true) : null;
        if (is_array($retro)) {
            $idx = 1;
            foreach ($retro as $turn) {
                if ($idx > 6) break;
                // Support multiple key-naming conventions used across environments
                $sim["Pregunta_$idx"]          = $turn['prompt']    ?? $turn['question'] ?? $turn['pregunta'] ?? null;
                $sim["Respuesta_$idx"]         = $turn['response']  ?? $turn['answer']   ?? $turn['respuesta'] ?? null;
                $sim["Retroalimentacion_$idx"] = $turn['feedback']  ?? $turn['retro']    ?? $turn['retroalimentacion'] ?? null;
                $idx++;
            }
        }
        // ── Parse score JSON (per-round points) ────────────────
        $scoreData = $r['score_json'] ? @json_decode($r['score_json'], true) : null;
        if (is_array($scoreData)) {
            $total = 0;
            // Handle both array-indexed and key-indexed formats
            $numeric_keys = array_filter(array_keys($scoreData), 'is_int');
            foreach ($scoreData as $k => $v) {
                if (!is_numeric($v)) continue;
                // Resolve round number from key
                if (is_int($k))                          $n = $k + 1;          // 0-indexed array
                elseif (is_numeric($k))                  $n = (int)$k;          // "1","2"…
                elseif (preg_match('/(\d+)/', $k, $m))   $n = (int)$m[1];      // "round1", "p1"…
                else continue;
                if ($n < 1 || $n > 6) continue;
                $sim["Puntos_$n"] = (float)$v;
                $total += (float)$v;
            }
            $sim['Puntos_Totales'] = $total;
            // Derive Calificacion if not set (max 50 pts → 100%)
            if ($sim['Calificacion'] == 0 && $total > 0)
                $sim['Calificacion'] = round($total / 50 * 100, 2);
        }
        $sims[] = $sim;
    }
    // ── 90-second file cache — avoids repeated slow DB2 queries ──
    $cacheKey  = 'demorp6_' . md5($ids_csv ?: implode(',', $ids));
    $cacheFile = sys_get_temp_dir() . '/bridge_cache_' . $cacheKey . '.json';
    $cacheTTL  = 90;
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = @json_decode(file_get_contents($cacheFile), true);
        if ($cached !== null) { out($cached); }
    }
    $payload = ['ok' => true, 'data' => $sims, 'total_records' => count($sims)];
    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
    out($payload);

// ════════════════════════════════════════════════════════
// DEFAULT – list all actions
// ════════════════════════════════════════════════════════
default:
    $actions=['ping','schema.tables','schema.columns','schema.full',
              'kpi.overview','kpi.activity_summary','kpi.leaderboard',
              'kpi.score_trend','kpi.score_distribution','kpi.completion_rate',
              'kpi.user_detail','kpi.sessions','kpi.login_activity',
              'kpi.coach_scores','kpi.session_detail',
              'list.activities','list.members','list.admins',
              'list.tags','list.assignments','list.supervisors',
              'sim.demorp6'];
    out(['ok'=>true,'bridge'=>'Rolplay Apotex Analytics Bridge v3.0',
         'db'=>DB_NAME,'actions'=>$actions]);
}
