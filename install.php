<?php
/**
 * ============================================================
 *  虎王科技快捷一站式安装器
 * ============================================================
 *  流程： 环境检测 → 点击下载 → 实时监测下载 → 点击安装 → 自动解压
 *  使用说明：
 *    1. 浏览器访问  /install.php
 *    2. 第一步「环境检测」，绿色即通过
 *    3. 点击「开始下载」，等待下载完成实时进度到 100%
 *    4. 点击「一键安装」，自动解压并清理临时文件
 * ============================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(600);          // 防止长任务超时
ignore_user_abort(false);

/** 配置文件（标题/下载地址/底部文字） */
define('CONFIG_FILE', __DIR__ . DIRECTORY_SEPARATOR . 'installer_config.json');
/** 临时文件（下载落地） */
define('TMP_ZIP', __DIR__ . DIRECTORY_SEPARATOR . 'shop.zip');
/** 安装完成标记 */
define('LOCK_FILE', __DIR__ . DIRECTORY_SEPARATOR . '.installed.lock');

/* ============================================================
 *  0.1 配置模块（标题 / 下载地址 / 底部文字，可自定义）
 * ============================================================ */
function default_config() {
    return [
        'title' => '程序在线安装器',
        'url'   => 'http://139.129.23.133:81/shop.zip',
        'footer'=> '沧州虎王科技 · 氢CMS 快捷安装器',
    ];
}

function load_config() {
    $def = default_config();
    if (is_file(CONFIG_FILE)) {
        $raw = json_decode((string)@file_get_contents(CONFIG_FILE), true);
        if (is_array($raw)) {
            foreach ($def as $k => $v) {
                if (isset($raw[$k]) && trim((string)$raw[$k]) !== '') {
                    $def[$k] = (string)$raw[$k];
                }
            }
        }
    }
    return $def;
}

/** 由已加载配置得到目标程序包地址 */
$__config = load_config();
define('PACKAGE_URL', $__config['url']);

/* ============================================================
 *  0. 输出控制器（纯文本/JSON，配合前端 JS 轮询）
 * ============================================================ */
function send($type, $msg, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['type' => $type, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  1. 环境检测模块
 * ============================================================ */
function check_environment() {
    $result = [];
    $ok     = true;

    // 1.1 PHP 版本
    $phpVersion = PHP_VERSION;
    $class = version_compare($phpVersion, '7.4.0', '>=') ? 'pass' : 'fail';
    $result[] = [
        'name'  => 'PHP 版本',
        'detail'=> '当前 ' . $phpVersion . '（要求 PHP >= 7.4）',
        'class' => $class,
        'block' => $class === 'fail',
    ];
    if ($class === 'fail') $ok = false;

    // 1.2 必需扩展（8 项 PHP 必需能力，缺失默认阻止安装）
    $needed = [
        ['name'      => 'PDO',
         'detail'    => 'PHP 数据库访问接口',
         'test'      => 'pdo'],
        ['name'      => 'pdo_mysql',
         'detail'    => '连接 MySQL/MariaDB 数据库',
         'test'      => 'pdo_mysql'],
        ['name'      => 'mbstring',
         'detail'    => '多字节字符串处理（中文等）',
         'test'      => 'mbstring'],
        ['name'      => 'gd',
         'detail'    => '验证码、图片缩略图等功能',
         'test'      => 'gd'],
        ['name'      => 'curl',
         'detail'    => 'HTTP 请求及外部服务对接',
         'test'      => 'curl'],
        ['name'      => 'openssl',
         'detail'    => '加密、安全通信等',
         'test'      => 'openssl'],
        ['name'      => 'json',
         'detail'    => 'JSON 数据处理',
         'test'      => 'json'],
        ['name'      => 'zip',
         'detail'    => '插件包、压缩包解压相关功能',
         'test'      => 'zip'],
    ];
    foreach ($needed as $ext) {
        $load   = extension_loaded($ext['test']);
        $class  = $load ? 'pass' : 'fail';
        $result[] = [
            'name'  => '扩展 ' . $ext['name'],
            'detail'=> ($load ? '✔ 已加载' : '✖ 未加载（' . $ext['detail'] . '）')
                       . ($load ? '' : ' —— 缺失默认阻止安装'),
            'class' => $class,
            'block' => !$load,
        ];
        if (!$load) $ok = false;
    }

    // 1.3 目录可写
    $writable = is_writable(__DIR__);
    $result[] = [
        'name'  => '目录可写',
        'detail'=> $writable ? '可写（' . __DIR__ . '）' : '不可写，请开放写入权限',
        'class' => $writable ? 'pass' : 'fail',
        'block' => !$writable,
    ];
    if (!$writable) $ok = false;

    // 1.4 ZipArchive 类存在（解压依赖）
    $hasZipClass = class_exists('ZipArchive');
    $result[] = [
        'name'  => 'ZipArchive 类',
        'detail'=> $hasZipClass ? '可用（用于自动解压）' : '不可用，无法解压',
        'class' => $hasZipClass ? 'pass' : 'fail',
        'block' => !$hasZipClass,
    ];
    if (!$hasZipClass) $ok = false;

    return ['ok' => $ok, 'items' => $result, 'blocked' => !$ok];
}

/* ============================================================
 *  2. 下载模块
 * ============================================================ */
function download_start() {
    // 用 curl 流式下载，写进度文件
    $progress = __DIR__ . DIRECTORY_SEPARATOR . '.download.progress';

    // 若已在下载/已下载完成且文件有效，直接返回
    if (is_file(TMP_ZIP) && filesize(TMP_ZIP) > 1000) {
        @file_put_contents($progress, "100\n");
        send('done', '检测到已存在的安装包，跳过下载', ['size' => filesize(TMP_ZIP)]);
    }

    // 后台进程下载，避免请求阻塞（Windows/Linux 双兼容写法）
    $cmd = PHP_BINARY . ' -r ' .
        escapeshellarg(
            'require ' . var_export(__FILE__, true) . ';'
        ) . ' download_worker';

    if (PHP_OS_FAMILY === 'Windows') {
        // Windows 下用 popen + start 分离进程
        @pclose(popen('start /B "" ' . $cmd, 'r'));
    } else {
        @exec($cmd . ' > /dev/null 2>&1 &');
    }

    // 兜底：若无法分离进程，则直接同步下载
    if (!is_file(TMP_ZIP)) {
        download_worker();
    }
    send('started', '下载任务已启动');
}

/** 下载工作进程：curl 下载 + 实时写进度 */
function download_worker() {
    $progress = __DIR__ . DIRECTORY_SEPARATOR . '.download.progress';
    @file_put_contents($progress, "0\n");

    $fp = @fopen(TMP_ZIP, 'wb');
    if (!$fp) {
        @file_put_contents($progress, "ERR:无法创建临时文件\n");
        return;
    }

    $ch = curl_init(PACKAGE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_PROGRESSFUNCTION => function ($ch, $dltotal, $dlnow) use ($progress) {
            if ($dltotal > 0) {
                $pct = (int)(($dlnow / $dltotal) * 100);
                if ($pct > 100) $pct = 100;
                @file_put_contents($progress, $pct . "\n" . $dlnow . "\n" . $dltotal . "\n");
            }
            return 0;
        },
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    // curl_close 自 PHP 8.0 起无实际作用且 8.5 弃用，省略之
    fclose($fp);

    if (!$ok || !is_file(TMP_ZIP) || filesize(TMP_ZIP) < 1000) {
        @file_put_contents($progress, "ERR:下载失败 " . $err);
        @unlink(TMP_ZIP);
    } else {
        @file_put_contents($progress, "100\n");
    }
}

/** 查询下载进度 */
function download_status() {
    $progress = __DIR__ . DIRECTORY_SEPARATOR . '.download.progress';
    $lines = is_file($progress) ? file($progress, FILE_IGNORE_NEW_LINES) : [];
    $pct = isset($lines[0]) ? trim($lines[0]) : '0';
    $now = isset($lines[1]) ? (int)$lines[1] : 0;
    $total = isset($lines[2]) ? (int)$lines[2] : 0;

    if (strpos($pct, 'ERR:') === 0) {
        send('error', str_replace('ERR:', '', $pct));
    }
    if ((int)$pct >= 100 && is_file(TMP_ZIP) && filesize(TMP_ZIP) > 1000) {
        send('done', '下载完成', ['pct' => 100, 'size' => filesize(TMP_ZIP)]);
    }
    send('progress', '下载中...', ['pct' => (int)$pct, 'now' => $now, 'total' => $total]);
}

/* ============================================================
 *  3. 自动解压安装模块
 * ============================================================ */
function install_run() {
    if (!is_file(TMP_ZIP)) {
        send('error', '未发现安装包，请先下载');
    }
    if (!class_exists('ZipArchive')) {
        send('error', '缺少 ZipArchive 扩展，无法解压');
    }

    $zip = new ZipArchive;
    if ($zip->open(TMP_ZIP) !== true) {
        send('error', '安装包打开失败（可能损坏）');
    }

    // 3.1 解压到临时目录，避免覆盖自身
    $extractDir = __DIR__ . DIRECTORY_SEPARATOR . '.install_tmp_' . time();
    if (!@mkdir($extractDir, 0777, true)) {
        $zip->close();
        send('error', '无法创建临时解压目录');
    }

    $count = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        // 防止路径穿越
        if (strpos($name, '..' . DIRECTORY_SEPARATOR) !== false || strpos($name, '..\\') !== false) {
            continue;
        }
        if ($zip->extractTo($extractDir, $name)) $count++;
    }
    $zip->close();

    if ($count === 0) {
        @delTree($extractDir);
        send('error', '解压结果为空，请检查安装包');
    }

    // 3.2 合并到站点根目录
    $moved = mergeTree($extractDir, __DIR__);

    // 3.3 清理临时文件
    @delTree($extractDir);
    @unlink(TMP_ZIP);
    @unlink(__DIR__ . DIRECTORY_SEPARATOR . '.download.progress');
    @file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));

    send('done', '下载完成，共解压 ' . $moved . ' 个文件', ['files' => $moved]);
}

/** 递归合并目录到目标 */
function mergeTree($from, $to) {
    $moved = 0;
    $items = scandir($from);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $from . DIRECTORY_SEPARATOR . $item;
        $dst = $to   . DIRECTORY_SEPARATOR . $item;
        // 跳过安装器自身与锁文件，避免覆盖正在运行的 install.php
        if (is_file($src) && in_array($item, ['install.php', '.installed.lock'])) continue;
        if (is_dir($src)) {
            if (!is_dir($dst)) @mkdir($dst, 0777, true);
            $moved += mergeTree($src, $dst);
        } else {
            if (@copy($src, $dst)) $moved++;
        }
    }
    return $moved;
}

/** 递归删除目录 */
function delTree($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($p) ? delTree($p) : @unlink($p);
    }
    @rmdir($dir);
}

/* ============================================================
 *  4. 路由分发
 * ============================================================ */
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'check':
        // __FILE__ 常量在 require 的 worker 里可能偏移，直接调用
        $env = check_environment();
        // 修正 worker 场景：透传后端完整检测结果与 blocked 标记
        send('env', '环境检测完成', [
            'ok'      => $env['ok'],
            'blocked' => $env['blocked'],
            'items'   => $env['items'],
        ]);
        break;

    case 'download_start':
        download_start();
        break;

    case 'download_status':
        download_status();
        break;

    case 'install':
        install_run();
        break;

    case 'lock_status':
        send('lock', is_file(LOCK_FILE) ? 'installed' : 'pending');
        break;

    case 'download_worker':
        download_worker();
        break;

    case 'cleanup':
        // 删除当前安装器文件本身，完成收尾
        @unlink(__FILE__);
        if (is_file(TMP_ZIP)) @unlink(TMP_ZIP);
        @unlink(__DIR__ . DIRECTORY_SEPARATOR . '.download.progress');
        send('done', '安装器已删除');
        break;

    default:
        // 无 action 时输出引导界面（HTML）
        output_page();
        break;
}

/* ============================================================
 *  5. 前端引导界面
 * ============================================================ */
function output_page() {
    global $__config;
    $alreadyInstalled = is_file(LOCK_FILE);
    $downloadExists = is_file(TMP_ZIP) && filesize(TMP_ZIP) > 1000;
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($__config['title']); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:"Segoe UI","Microsoft YaHei",sans-serif;background:radial-gradient(1200px 800px at 70% -10%,#1e3a8a 0%,#101d3c 45%,#0a1428 100%);color:#e2ecf8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.card{width:100%;max-width:720px;background:#0f1f3f;border:1px solid #274180;border-radius:20px;box-shadow:0 25px 60px rgba(2,8,24,.6);overflow:hidden;}
.header{padding:28px 32px;background:linear-gradient(135deg,#1d4ed8,#2563eb);position:relative;}
.header h1{font-size:22px;color:#ffffff;font-weight:700;}
.header p{color:#bfdbfe;font-size:13px;margin-top:6px;}
.steps{display:flex;padding:0 32px;border-bottom:1px solid #1f3a6b;background:#0d1830;}
.step{flex:1;padding:18px 0;text-align:center;font-size:13px;color:#6482b8;position:relative;font-weight:600;}
.step.active{color:#93c5fd;}
.step.done{color:#4ade80;}
.step .num{display:inline-flex;width:26px;height:26px;border-radius:50%;background:#1f3a6b;color:#93c5fd;align-items:center;justify-content:center;margin-right:8px;font-size:12px;}
.step.active .num,.step.done .num{background:#3b82f6;color:#fff;}
.body{padding:32px;}
/* env */
.env-item{display:flex;align-items:center;padding:12px 14px;background:#0f1f3f;border:1px solid #274180;border-radius:12px;margin-bottom:10px;}
.env-item .ico{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-right:14px;flex-shrink:0;}
.env-item.pass .ico{background:#052e16;color:#4ade80;}
.env-item.fail .ico{background:#450a0a;color:#f87171;}
.env-item .name{font-size:14px;font-weight:600;flex:1;}
.env-item .detail{font-size:12px;color:#86b0e0;}
/* btn */
.btn{display:inline-flex;align-items:center;justify-content:center;border:none;cursor:pointer;font-size:15px;font-weight:700;border-radius:12px;padding:14px 28px;transition:.2s;background:#1f3a6b;color:#93c5fd;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.btn.primary{background:linear-gradient(135deg,#2563eb,#3b82f6);color:#fff;}
.btn.primary:hover:not(:disabled){filter:brightness(1.1);transform:translateY(-1px);box-shadow:0 8px 20px rgba(37,99,235,.4);}
.btn.success{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;}
.btn.success:hover:not(:disabled){box-shadow:0 8px 20px rgba(34,197,94,.35);}
.btn.warning{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;}
/* progress */
.progress-wrap{margin:24px 0;}
.progress-bar{height:24px;background:#0d1830;border:1px solid #1f3a6b;border-radius:12px;overflow:hidden;box-shadow:inset 0 2px 4px rgba(0,0,0,.4);}
.progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#2563eb,#38bdf8);border-radius:12px;transition:width .4s ease;display:flex;align-items:center;justify-content:flex-end;padding-right:10px;font-size:12px;color:#fff;font-weight:700;white-space:nowrap;}
.progress-info{margin-top:10px;font-size:13px;color:#86b0e0;display:flex;justify-content:space-between;}
.actions{margin-top:28px;display:flex;gap:14px;justify-content:center;}
.msg{margin-top:20px;padding:14px 16px;border-radius:12px;font-size:14px;font-weight:600;}
.msg.ok{background:#052e16;color:#4ade80;}
.msg.err{background:#450a0a;color:#f87171;}
.msg.info{background:#172a52;color:#93c5fd;}
.hint{font-size:12px;color:#6482b8;margin-top:16px;text-align:center;}
.footer{padding:18px 32px;border-top:1px solid #1f3a6b;text-align:center;font-size:13px;color:#93c5fd;background:#0d1830;font-weight:600;}
code{background:#172a52;padding:2px 6px;border-radius:4px;color:#93c5fd;font-size:12px;}
</style>
</head>
<body>
<div class="card">
  <div class="header">
    <h1>🚀 <span id="app-title"><?php echo htmlspecialchars($__config['title']); ?></span></h1>
    <p>环境检测 → 下载安装包 → 自动解压安装</p>
  </div>

  <div class="steps">
    <div class="step active" id="st1"><span class="num">1</span>环境检测</div>
    <div class="step" id="st2"><span class="num">2</span>下载程序</div>
    <div class="step" id="st3"><span class="num">3</span>自动安装</div>
  </div>

  <div class="body">
    <!-- 步骤1：环境检测 -->
    <div id="panel-env">
      <div id="env-list"></div>
      <div class="actions">
        <button class="btn primary" id="btn-check">🔍 检测环境</button>
        <button class="btn primary" id="btn-dl" disabled>⬇️ 开始下载</button>
        <button class="btn warning" id="btn-force" style="display:none;">⚡ 强制安装</button>
      </div>
    </div>

    <!-- 步骤2：下载 -->
    <div id="panel-dl" style="display:none;">
      <div class="msg info" id="dl-msg">正在启动下载...</div>
      <div class="progress-wrap">
        <div class="progress-bar"><div class="progress-fill" id="dl-fill">0%</div></div>
        <div class="progress-info">
          <span id="dl-now">0 MB</span>
          <span id="dl-total">/ 0 MB</span>
        </div>
      </div>
      <div class="actions">
        <button class="btn warning" id="btn-install" disabled>📦 一键安装（解压）</button>
      </div>
    </div>

    <!-- 步骤3：安装 -->
    <div id="panel-install" style="display:none;">
      <div class="msg info" id="ins-msg">正在自动解压安装...</div>
      <div class="progress-wrap">
        <div class="progress-bar"><div class="progress-fill" id="ins-fill">0%</div></div>
      </div>
      <div class="actions">
        <button class="btn success" id="btn-finish">🗑️ 删除安装器并访问网站</button>
      </div>
      <div class="hint">解压完成后自动清理 <code>shop.zip</code> 与临时文件</div>
    </div>

    <div id="global-msg"></div>
  </div>

  <div class="footer">
    <span id="app-footer"><?php echo htmlspecialchars($__config['footer']); ?></span>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/axios@1.6.8/dist/axios.min.js"></script>
<script>
const PACKAGE_URL = <?php echo json_encode(PACKAGE_URL); ?>;
const API = (a) => 'install.php?action=' + a;
let envOk = false, dlPct = 0, finished = false, forced = false;
const $ = (id) => document.getElementById(id);

function setStep(n){
  ['st1','st2','st3'].forEach((id,i)=>{ $(id).className='step'+(i<n?' done':i===n?' active':''); });
}

/* ---------- 步骤1：环境检测 ---------- */
async function doCheck(){
  $('env-list').innerHTML = '<div class="msg info">检测中...</div>';
  try{
    const r = await axios.get(API('check'));
    const d = r.data;
    envOk = d.ok;
    let html='';
    (d.items||[]).forEach(it=>{
      html += `<div class="env-item ${it.class}">
        <div class="ico">${it.class==='pass'?'✔':'✖'}</div>
        <div class="name">${escapeHtml(it.name)}</div>
        <div class="detail">${escapeHtml(it.detail)}</div>
      </div>`;
    });
    $('env-list').innerHTML = html;
    $('btn-dl').disabled = !envOk;
    // 存在缺失扩展/能力时，显示「强制安装」按钮以允许跳过
    if(d.blocked){
      $('btn-force').style.display='inline-flex';
    } else {
      $('btn-force').style.display='none';
    }
    showGlobal(envOk ? '✅ 环境检测通过，可以开始下载' : '❌ 检测到缺失项，可点击「强制安装」跳过继续', envOk?'ok':'err');
    if(envOk) setStep(0);
  }catch(e){
    $('env-list').innerHTML='<div class="msg err">检测失败</div>';
    showGlobal('环境检测请求失败：'+e.message,'err');
  }
}

/* ---------- 步骤2：下载 + 进度监测 ---------- */
async function startDownload(){
  setStep(1);
  $('panel-env').style.display='none';
  $('panel-dl').style.display='block';
  $('dl-msg').textContent='正在启动下载任务...';
  try{
    await axios.get(API('download_start'));
  }catch(e){ /* 忽略，靠轮询判断 */ }
  pollDownload();
}

let pollTimer = null;
async function pollDownload(){
  clearInterval(pollTimer);
  try{
    const r = await axios.get(API('download_status'));
    const d = r.data;
    if(d.pct){
      $('dl-fill').style.width=d.pct+'%';
      $('dl-fill').textContent=(d.pct>=12? d.pct+'%':'');
      $('dl-now').textContent=fmt(d.now)+' MB';
      $('dl-total').textContent='/ '+fmt(d.total)+' MB';
      $('dl-msg').textContent='实时监测中：下载 '+d.pct+'%';
      dlPct=d.pct;
    }
    if(d.type==='done'){
      clearInterval(pollTimer);
      $('dl-msg').innerHTML='<div class="msg ok">✔ '+escapeHtml(d.msg)+'（'+fmt(d.size)+' MB）</div>';
      $('btn-install').disabled=false;
      setStep(2);
    } else if(d.type==='error'){
      clearInterval(pollTimer);
      $('dl-msg').innerHTML='<div class="msg err">✖ '+escapeHtml(d.msg)+'</div>';
    } else {
      pollTimer=setInterval(pollDownload,1200);
    }
  }catch(e){
    // 循环兜底：继续轮询直到文件出现
    if(!finished) pollTimer=setInterval(pollDownload,2000);
  }
}

/* ---------- 步骤3：自动解压安装 ---------- */
async function startInstall(){
  finished=false;
  $('panel-dl').style.display='none';
  $('panel-install').style.display='block';
  $('ins-msg').innerHTML='<div class="msg info">正在自动解压，请稍候...</div>';
  let anim=0;
  const animTimer=setInterval(()=>{ anim=(anim+18)%100; $('ins-fill').style.width=anim+'%'; },250);
  try{
    const r = await axios.get(API('install'));
    const d = r.data;
    clearInterval(animTimer);
    $('ins-fill').style.width='100%';
    if(d.type==='done'){
      finished=true;
      $('ins-msg').innerHTML='<div class="msg ok">🎉 '+escapeHtml(d.msg)+'</div>';
      $('btn-finish').style.display='inline-flex';
    } else {
      $('ins-msg').innerHTML='<div class="msg err">✖ '+escapeHtml(d.msg)+'</div>';
      $('btn-finish').style.display='none';
    }
  }catch(e){
    clearInterval(animTimer);
    $('ins-msg').innerHTML='<div class="msg err">安装请求失败：'+escapeHtml(e.message)+'</div>';
  }
}

async function finishInstall(){
  // 通知后端删除当前 install.php
  try{ await axios.get(API('cleanup')); }catch(e){ }
  // 删除后跳转到站点首页
  location.href = './';
}

/* ---------- 工具 ---------- */
function fmt(b){ b=Number(b)||0; return (b/1024/1024).toFixed(1); }
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function showGlobal(t,cls){ $('global-msg').innerHTML = '<div class="msg '+cls+'">'+escapeHtml(t)+'</div>'; }

/* ---------- 强制安装：跳过缺失扩展进入下载 ---------- */
function forceInstall(){
  // 记录强制安装标记，解除下载按钮锁定
  forced = true;
  $('btn-dl').disabled = false;
  showGlobal('⚡ 已强制安装：忽略缺失扩展，可继续', 'err');
}

/* ---------- 事件绑定 ---------- */
$('btn-check').onclick = doCheck;
$('btn-dl').onclick = startDownload;
$('btn-install').onclick = startInstall;
$('btn-finish').onclick = finishInstall;
$('btn-force').onclick = forceInstall;

/* 页面加载：若已安装则直接提示 */
(function(){
  axios.get(API('lock_status')).then(r=>{ if(r.data.msg==='installed'){ showGlobal('检测到已完成安装，可以直接使用','ok'); }}).catch(()=>{});
})();
</script>
</body>
</html>
    <?php
}