<?php
/* PAGE: pocket_photo_editor_software.php
   ------------------------------------------------------------
   A simplified prompt‑driven photo editor with a rich, dark UI.
   Features:
   - Drag and drop (or tap) to upload an image. On mobile, tapping opens the file picker.
   - Every edit builds on the current version; versions are chained and persisted per session.
   - A history strip shows all previous versions; click a thumbnail to preview and select it.
   - A sticky prompt bar at the bottom lets you describe your edit and generate a new version.
   - Rollback/Undo controls let you revert to any previous version or step back one edit.
   - Download button saves the currently viewed image. Undo button steps back one version.
   - Clicking the main image opens a zoomable full‑screen overlay.
   The design uses a neutral grey palette reminiscent of Adobe CC, avoiding bright blues.
*/

/* =========================
 * SECTION: CONFIG
 * ========================= */
$API_KEY  = 'AIzaSyBO2YHmMWp7yg9kRu-srY_-B8MDs_mI8Yk'; // Replace with your Gemini API key
$MODEL    = 'gemini-2.5-flash-image';
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/$MODEL:generateContent";

// Storage: /generated/ppe_software/<session-id>/
$ROOT_DIR = __DIR__ . '/generated/ppe_software';
$ROOT_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/generated/ppe_software';
$SESSION_COOKIE = 'ppe_software_session';

/* =========================
 * SECTION: BOOTSTRAP (folders + session + DB)
 * ========================= */
if (!is_dir($ROOT_DIR)) { @mkdir($ROOT_DIR, 0755, true); }
if (empty($_COOKIE[$SESSION_COOKIE])) {
  $sid = bin2hex(random_bytes(8));
  setcookie($SESSION_COOKIE, $sid, time() + 60*60*24*30, "/");
} else {
  // Sanitize session id
  $sid = preg_replace('~[^a-zA-Z0-9]~', '', $_COOKIE[$SESSION_COOKIE]);
}
$BUCKET_DIR = $ROOT_DIR . '/' . $sid;
$BUCKET_URL = $ROOT_URL . '/' . $sid;
if (!is_dir($BUCKET_DIR)) { @mkdir($BUCKET_DIR, 0755, true); }

// DB file for this session
$DB_PATH = $BUCKET_DIR . '/db.json';
if (!file_exists($DB_PATH)) {
  file_put_contents($DB_PATH, json_encode([
    'versions'          => [], // newest first
    'current_base_path' => null,
    'original_path'     => null
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* =========================
 * SECTION: HELPERS
 * ========================= */
function db_read(string $path): array {
  $json = @file_get_contents($path);
  return json_decode($json, true) ?: [
    'versions' => [],
    'current_base_path' => null,
    'original_path' => null
  ];
}
function db_write(string $path, array $data): bool {
  $fp = fopen($path, 'c+');
  if (!$fp) return false;
  flock($fp, LOCK_EX);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return true;
}
function safe_name(string $prefix, string $ext): string {
  return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
}
function ext_for_mime(string $mime): string {
  return match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    default      => 'png'
  };
}
// Convert unsupported formats (e.g. HEIC) to PNG if Imagick is available
function convert_to_png(string $src): string {
  $out = preg_replace('~\.[A-Za-z0-9]+$~', '', $src) . '.png';
  if (class_exists('Imagick')) {
    try {
      $im = new Imagick($src);
      $im->setImageFormat('png');
      if ($im->getImageAlphaChannel()) {
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
      }
      $im->writeImage($out);
      $im->destroy();
      return $out;
    } catch (Throwable $e) {
      @copy($src, $out);
      return $out;
    }
  }
  @copy($src, $out);
  return $out;
}
// Save uploaded file; convert unsupported formats; return [ok, error, savedPath, mime, originalPath]
function save_upload(array $file, string $dir): array {
  $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
  if ($err !== UPLOAD_ERR_OK) {
    $map = [1=>'File too large (php.ini)',2=>'File too large (form)',3=>'Upload interrupted',4=>'No file uploaded',6=>'Missing temp folder',7=>'Failed to write',8=>'Blocked by extension'];
    return [false, $map[$err] ?? ('Upload error '.$err), null, null, null];
  }
  $tmp  = $file['tmp_name'];
  $mime = mime_content_type($tmp) ?: 'application/octet-stream';
  $supported = ['image/jpeg','image/png','image/webp'];
  if (!in_array($mime, $supported, true)) {
    $orig = rtrim($dir, '/') . '/' . safe_name('orig', 'bin');
    @move_uploaded_file($tmp, $orig) || @copy($tmp, $orig);
    $png = convert_to_png($orig);
    return [true, null, $png, 'image/png', $orig];
  } else {
    $ext = ext_for_mime($mime);
    $dest = rtrim($dir, '/') . '/' . safe_name('orig', $ext);
    @move_uploaded_file($tmp, $dest) || @copy($tmp, $dest);
    return [true, null, $dest, $mime, $dest];
  }
}
// Save base64 image to disk; return path
function save_b64(string $dir, string $b64, string $mime, string $prefix='img'): string {
  $ext = ext_for_mime($mime);
  $path = rtrim($dir, '/') . '/' . safe_name($prefix, $ext);
  file_put_contents($path, base64_decode($b64));
  return $path;
}
// Call Gemini API to generate image from prompt and base
function call_api(string $key, string $endpoint, string $prompt, string $basePath): array {
  $parts = [];
  if ($prompt !== '') {
    $parts[] = ['text' => $prompt];
  }
  if ($basePath && is_file($basePath)) {
    $mime  = mime_content_type($basePath) ?: 'image/png';
    $bytes = file_get_contents($basePath);
    if ($bytes !== false) {
      $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
    }
  }
  $body = ['contents' => [ ['parts' => $parts] ]];
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => $endpoint . '?key=' . urlencode($key),
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_TIMEOUT        => 180,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($res === false) return [false, "cURL error: $err", null];
  if ($code !== 200)   return [false, "HTTP $code\n$res", null];
  return [true, null, $res];
}
// Parse API response to extract image and metadata
function parse_image(string $json): array {
  $j = json_decode($json, true);
  $parts = $j['candidates'][0]['content']['parts'] ?? [];
  foreach ($parts as $p) {
    if (isset($p['inlineData']['data'])) {
      return [$p['inlineData']['data'], $p['inlineData']['mimeType'] ?? 'image/png', $j['usageMetadata'] ?? null];
    }
    if (isset($p['inline_data']['data'])) {
      return [$p['inline_data']['data'], $p['inline_data']['mime_type'] ?? 'image/png', $j['usage_metadata'] ?? null];
    }
  }
  return [null, null, null];
}

/* =========================
 * SECTION: ROUTER (AJAX endpoints)
 * ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $db = db_read($DB_PATH);
  // Upload action
  if ($_POST['action'] === 'upload') {
    if (!isset($_FILES['photo'])) {
      echo json_encode(['ok' => 0, 'error' => 'No file']); exit;
    }
    [$ok, $err, $saved, $mime, $orig] = save_upload($_FILES['photo'], $BUCKET_DIR);
    if (!$ok) {
      echo json_encode(['ok' => 0, 'error' => $err]); exit;
    }
    $ver = [
      'id'        => uniqid('ver_', true),
      'timestamp' => date('c'),
      'type'      => 'original',
      'path'      => $saved,
      'url'       => $BUCKET_URL . '/' . basename($saved),
      'prompt'    => null,
      'base'      => null,
      'usage'     => null
    ];
    array_unshift($db['versions'], $ver);
    $db['original_path']     = $saved;
    $db['current_base_path'] = $saved;
    db_write($DB_PATH, $db);
    echo json_encode(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]);
    exit;
  }
  // Edit action: generate new image using current base
  if ($_POST['action'] === 'edit') {
    $prompt = trim($_POST['prompt'] ?? '');
    if ($prompt === '') {
      echo json_encode(['ok' => 0, 'error' => 'Enter a prompt']); exit;
    }
    $base = $db['current_base_path'] ?? null;
    if (!$base || !is_file($base)) {
      echo json_encode(['ok' => 0, 'error' => 'Upload an image first']); exit;
    }
    [$ok, $err, $resp] = call_api($API_KEY, $ENDPOINT, $prompt, $base);
    if (!$ok) {
      echo json_encode(['ok' => 0, 'error' => $err]); exit;
    }
    [$b64, $mime, $usage] = parse_image($resp);
    if (!$b64) {
      echo json_encode(['ok' => 0, 'error' => 'No image returned', 'raw' => $resp]); exit;
    }
    $out  = save_b64($BUCKET_DIR, $b64, $mime, 'edit');
    $ver = [
      'id'        => uniqid('ver_', true),
      'timestamp' => date('c'),
      'type'      => 'edit',
      'path'      => $out,
      'url'       => $BUCKET_URL . '/' . basename($out),
      'prompt'    => $prompt,
      'base'      => $base,
      'usage'     => $usage
    ];
    array_unshift($db['versions'], $ver);
    $db['current_base_path'] = $out;
    db_write($DB_PATH, $db);
    echo json_encode(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]);
    exit;
  }
  // Undo action: step back one edit (skip original)
  if ($_POST['action'] === 'undo') {
    $cur = $db['current_base_path'] ?? null;
    if (!$cur) {
      echo json_encode(['ok' => 0, 'error' => 'Nothing to undo']); exit;
    }
    $prev = null;
    foreach ($db['versions'] as $v) {
      if (($v['path'] ?? '') === $cur) {
        $prev = $v['base'] ?? null;
        break;
      }
    }
    if ($prev && is_file($prev)) {
      $db['current_base_path'] = $prev;
      db_write($DB_PATH, $db);
      echo json_encode(['ok' => 1, 'current_base' => $prev]); exit;
    } else {
      echo json_encode(['ok' => 0, 'error' => 'Already at original']); exit;
    }
  }
  // Rollback action: set base to a chosen version
  if ($_POST['action'] === 'rollback') {
    $path = $_POST['path'] ?? '';
    if (!$path || !is_file($path)) {
      echo json_encode(['ok' => 0, 'error' => 'Invalid version']); exit;
    }
    $db['current_base_path'] = $path;
    db_write($DB_PATH, $db);
    echo json_encode(['ok' => 1, 'current_base' => $path]); exit;
  }
  // List action: return all versions and current base
  if ($_POST['action'] === 'list') {
    echo json_encode(['ok' => 1, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]); exit;
  }
  echo json_encode(['ok' => 0, 'error' => 'Unknown action']); exit;
}

/* =========================
 * SECTION: VIEW (HTML + CSS + JS)
 * ========================= */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pocket Photo Editor — Software Style</title>
<!-- Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
/* === Base Theme === */
:root{
  --bg:#0b0f17;        /* Deep background */
  --panel:#0f1526;     /* Panel background */
  --edge:#24355a;      /* Panel border */
  --text:#e5e9f4;      /* Primary text */
  --muted:#9aa6c5;     /* Secondary text */
  --pill-gradient: conic-gradient(from 0deg, #4e515a, #55585f, #4e515a, #5d6068); /* Grey gradient for pill border */
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:linear-gradient(180deg,var(--bg),#0a0e18 60%,var(--bg));
  color:var(--text);
  font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
}
/* === Layout === */
.wrap{
  max-width:1180px;
  margin:18px auto;
  padding:0 12px;
  display:grid;
  grid-template-columns:1fr 320px;
  gap:18px;
}
@media(max-width:960px){
  .wrap{grid-template-columns:1fr}
}
/* Card */
.card{
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
  border:1px solid var(--edge);
  border-radius:16px;
  box-shadow:0 12px 40px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.03);
  padding:14px;
}
/* Header Row */
.hRow{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
}
.badge{
  font-size:12px;
  color:#dbe5ff;
  background:#141b2f;
  border:1px solid #26345b;
  padding:5px 10px;
  border-radius:999px;
}
/* Drop Area */
.drop{
  border:1.5px dashed #3b4f78;
  border-radius:14px;
  padding:26px;
  text-align:center;
  background:linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
  cursor:pointer;
  position:relative;
}
.drop input[type=file]{
  position:absolute; inset:0; opacity:0; cursor:pointer;
}
.drop strong{font-weight:700; display:block; margin-bottom:6px;}
.help{font-size:12px; color:var(--muted);}
/* Viewer */
.viewer{
  border:1px solid var(--edge);
  border-radius:16px;
  overflow:hidden;
  background:#0c1224;
  position:relative;
  min-height:480px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.viewer img{
  max-width:100%;
  max-height:100%;
  display:block;
  cursor:zoom-in;
}
.skeleton{
  position:relative;
  width:100%;
  height:100%;
  min-height:480px;
  background:#101a33;
}
.skeleton::after{
  content:"";
  position:absolute; inset:0;
  background:linear-gradient(110deg, rgba(255,255,255,.04) 8%, rgba(255,255,255,.14) 18%, rgba(255,255,255,.04) 33%);
  background-size:200% 100%;
  animation:shim 1.2s infinite;
}
@keyframes shim{0%{background-position:-200% 0}100%{background-position:200% 0}}
/* Gallery */
.gallery{
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(100px,1fr));
  gap:10px;
}
.thumb{
  position:relative;
  border:1px solid var(--edge);
  border-radius:10px;
  overflow:hidden;
  background:#0c1224;
  cursor:pointer;
}
.thumb img{
  width:100%; height:90px; object-fit:cover; display:block;
}
.thumb .meta{
  position:absolute;
  left:6px; bottom:6px;
  background:rgba(9,14,28,.7);
  padding:2px 6px;
  border-radius:999px;
  font-size:10px;
  border:1px solid rgba(255,255,255,.06);
}
/* Buttons */
.btn{
  border:1px solid var(--edge);
  background:#1a233a;
  color:var(--text);
  border-radius:12px;
  padding:10px 14px;
  font-weight:600;
  cursor:pointer;
  text-align:center;
}
.btn:hover{background:#243055;}
.pill{
  position:relative;
  border-radius:999px;
  padding:2px;
  background:var(--pill-gradient);
  animation:spin 6s linear infinite;
}
.pill::before{
  content:"";
  position:absolute; inset:2px;
  border-radius:999px;
  background:linear-gradient(180deg,#111b34,#0b1427);
}
.pill .inner{
  position:relative;
  display:flex;
  align-items:center;
  gap:8px;
  padding:8px 14px;
  border-radius:999px;
  color:#e5eaf5;
  font-weight:700;
  cursor:pointer;
}
@keyframes spin{to{filter:hue-rotate(360deg)}}
/* Top overlay buttons (download & undo) */
.topButtons{
  position:absolute;
  top:8px;
  right:8px;
  display:flex;
  gap:10px;
  z-index:10;
}
/* Bottom bar */
.bottomBar{
  position:sticky;
  bottom:0;
  z-index:20;
  background:linear-gradient(180deg, rgba(11,15,23,0), rgba(11,15,23,.92) 30%, rgba(11,15,23,1));
  padding:10px 0;
  border-top:1px solid rgba(255,255,255,.07);
}
.bottomCard{
  max-width:1180px;
  margin:0 auto;
  padding:12px 14px;
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
  border:1px solid var(--edge);
  border-radius:16px;
  box-shadow:0 10px 40px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.03);
}
.promptRow{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.prompt{
  flex:1;
  min-height:64px;
  padding:12px;
  border:1px solid var(--edge);
  border-radius:12px;
  background:#0c1224;
  color:#e5eaf5;
  font:inherit;
  resize:vertical;
}
/* Overlay for zoom */
.overlay{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.8);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:100;
  cursor:zoom-out;
}
.overlay.open{display:flex;}
.overlay img{
  max-width:90vw;
  max-height:90vh;
  display:block;
  transition:transform .2s ease;
}
/* Error */
.error{
  white-space:pre-wrap;
  color:#ff98a5;
  background:#2a1220;
  border:1px solid #5e1c2a;
  padding:10px;
  border-radius:12px;
  margin-top:8px;
}
</style>
</head>
<body>
<div class="wrap">
  <!-- Left: Upload & Viewer -->
  <div class="card">
    <!-- Upload panel / viewer control-->
    <div class="grid2" style="display:grid; grid-template-columns:1fr; gap:18px;">
      <!-- Upload / Controls / Viewer -->
      <div>
        <div id="dropArea" class="drop">
          <strong>Drag & drop an image here</strong>
          <span class="help">Tap to choose from your camera roll</span>
          <input id="fileInput" type="file" accept="image/*">
        </div>
        <div id="uploadMsg" class="help" style="margin-top:10px"></div>
      </div>
      <div>
        <div id="viewer" class="viewer">
          <div class="help" style="padding:12px">Upload an image to start</div>
          <!-- Buttons top right -->
          <div class="topButtons" id="topButtons" style="display:none">
            <a id="downloadBtn" class="btn" href="#" download>Download</a>
            <button id="undoBtn" class="btn">Undo</button>
          </div>
        </div>
        <div id="errorBox" class="error" style="display:none"></div>
      </div>
    </div>
  </div>
  <!-- Right: History -->
  <div class="card" style="overflow:auto; max-height: calc(100vh - 200px)">
    <div class="hRow" style="margin-bottom:10px">
      <div style="font-weight:800">History</div>
      <div class="help">Click to preview & select • Use Roll Back below</div>
    </div>
    <div id="gallery" class="gallery"></div>
  </div>
</div>
<!-- Bottom Prompt Bar -->
<div class="bottomBar">
  <div class="bottomCard">
    <div class="promptRow">
      <textarea id="prompt" class="prompt" placeholder="Describe your edit… e.g., lighten skin; add warm glow; boost contrast"></textarea>
      <div id="generateBtn" class="pill"><div class="inner">Generate</div></div>
    </div>
    <div class="btnRow" style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap">
      <button id="rollbackBtn" class="btn">Roll Back to Selected</button>
      <span class="help">Selected = last clicked thumbnail</span>
    </div>
  </div>
</div>
<!-- Overlay for zoom -->
<div id="overlay" class="overlay">
  <img id="overlayImg" src="" alt="Zoomed image">
</div>
<!-- JavaScript -->
<script>
/* PAGE: pocket_photo_editor_software.php */
// ===== State =====
let SELECTED = null; // Selected version for rollback
const viewer   = document.getElementById('viewer');
const gallery  = document.getElementById('gallery');
const errorBox = document.getElementById('errorBox');
const uploadMsg= document.getElementById('uploadMsg');
const downloadBtn = document.getElementById('downloadBtn');
const topButtons = document.getElementById('topButtons');
const overlay   = document.getElementById('overlay');
const overlayImg= document.getElementById('overlayImg');
// ===== Helpers =====
function setError(msg){ errorBox.style.display='block'; errorBox.textContent=msg; }
function clearError(){ errorBox.style.display='none'; errorBox.textContent=''; }
function showSkeleton(){ viewer.querySelector('div.help')?.remove(); viewer.innerHTML = '<div class="skeleton"></div><div class="topButtons" id="topButtons" style="display:none"></div>'; }
// Update viewer with image and update download link
function setViewer(url){
  viewer.innerHTML = '<img id="canvasImg" src="'+url+'" alt="Image">';
  downloadBtn.href = url;
  // show top buttons
  topButtons.style.display='flex';
  // attach click for zoom
  const img = document.getElementById('canvasImg');
  img.addEventListener('click', ()=>{
    overlayImg.src = url;
    overlay.classList.add('open');
    // reset scale
    overlayImg.dataset.scale = '1';
    overlayImg.style.transform = 'scale(1)';
  });
}
function updateGallery(items){
  gallery.innerHTML='';
  if(!items || !items.length){ gallery.innerHTML = '<div class="help">No versions yet.</div>'; return; }
  items.forEach(v => {
    const a = document.createElement('a');
    a.className = 'thumb';
    a.href = 'javascript:void(0)';
    a.innerHTML = '<img src="'+v.url+'" alt=""><div class="meta">'+v.type+'</div>';
    a.addEventListener('click', ()=>{
      setViewer(v.url);
      setSelected(a, v);
    });
    gallery.appendChild(a);
  });
}
function setSelected(el, data){
  SELECTED = data;
  // highlight selected
  gallery.querySelectorAll('.thumb').forEach(t=> t.style.outline='none');
  if (el) el.style.outline='2px solid #5d6070';
}
async function postForm(formData){
  const res = await fetch(location.href, { method:'POST', body: formData });
  return await res.json();
}
// ===== Upload handling =====
const dropArea = document.getElementById('dropArea');
const fileInput = document.getElementById('fileInput');
['dragenter','dragover'].forEach(ev=>{
  dropArea.addEventListener(ev, e=>{ e.preventDefault(); dropArea.style.borderColor = '#5d6070'; });
});
['dragleave','drop'].forEach(ev=>{
  dropArea.addEventListener(ev, e=>{ e.preventDefault(); dropArea.style.borderColor = '#3b4f78'; });
});
dropArea.addEventListener('drop', e=>{
  if (e.dataTransfer.files && e.dataTransfer.files[0]) handleUpload(e.dataTransfer.files[0]);
});
dropArea.addEventListener('click', () => fileInput.click());
fileInput.addEventListener('change', e=>{
  if (e.target.files[0]) handleUpload(e.target.files[0]);
});
async function handleUpload(file){
  clearError(); uploadMsg.textContent = 'Uploading…';
  const fd = new FormData(); fd.append('action','upload'); fd.append('photo', file);
  try{
    const j = await postForm(fd);
    if (!j.ok){ setError(j.error || 'Upload failed'); uploadMsg.textContent=''; return; }
    setViewer(j.version.url);
    updateGallery(j.all);
    uploadMsg.textContent = 'Uploaded ✓';
    // auto select first thumb
    const first = gallery.querySelector('.thumb'); if (first) first.click();
  }catch(err){ setError(err.message||String(err)); uploadMsg.textContent=''; }
}
// ===== Generate =====
document.getElementById('generateBtn').addEventListener('click', async ()=>{
  const promptVal = document.getElementById('prompt').value.trim();
  if (!promptVal){ setError('Enter a prompt'); return; }
  clearError(); showSkeleton();
  const fd = new FormData(); fd.append('action','edit'); fd.append('prompt', promptVal);
  try{
    const j = await postForm(fd);
    if (!j.ok){ setError(j.error || 'Edit failed'); return; }
    setViewer(j.version.url);
    updateGallery(j.all);
    // auto select first new version
    const first = gallery.querySelector('.thumb'); if (first) first.click();
    document.getElementById('prompt').value = '';
  }catch(err){ setError(err.message||String(err)); }
});
// ===== Undo =====
document.getElementById('undoBtn').addEventListener('click', async ()=>{
  const fd = new FormData(); fd.append('action','undo');
  try{
    const j = await postForm(fd);
    if (j.ok){
      // fetch list to update gallery and viewer
      const fd2 = new FormData(); fd2.append('action','list');
      const k = await postForm(fd2);
      if (k.ok){ updateGallery(k.all);
        // find current base item
        const target = k.all.find(v => v.path === j.current_base) || k.all[0];
        if (target){ setViewer(target.url); }
      }
    } else { setError(j.error || 'Undo failed'); }
  }catch(err){ setError(err.message||String(err)); }
});
// ===== Rollback to selected =====
document.getElementById('rollbackBtn').addEventListener('click', async ()=>{
  if (!SELECTED){ setError('Tap a thumbnail first'); return; }
  const fd = new FormData(); fd.append('action','rollback'); fd.append('path', SELECTED.path);
  try{
    const j = await postForm(fd);
    if (j.ok){ setViewer(SELECTED.url); }
    else { setError(j.error || 'Rollback failed'); }
  }catch(err){ setError(err.message||String(err)); }
});
// ===== Overlay controls =====
overlay.addEventListener('click', () => { overlay.classList.remove('open'); });
// Zoom on scroll
overlayImg.addEventListener('wheel', (e) => {
  e.preventDefault();
  const delta = Math.sign(e.deltaY);
  let scale = parseFloat(overlayImg.dataset.scale || '1');
  if (delta < 0) scale += 0.1; // scroll up: zoom in
  else scale -= 0.1;           // scroll down: zoom out
  scale = Math.max(0.5, Math.min(5, scale));
  overlayImg.dataset.scale = scale;
  overlayImg.style.transform = 'scale(' + scale + ')';
});
// ===== Initial load: load history if exists =====
(async function init(){
  try{
    const fd = new FormData(); fd.append('action','list');
    const j = await postForm(fd);
    if (j.ok){ updateGallery(j.all);
      if (j.current_base){
        const target = j.all.find(v => v.path === j.current_base) || j.all[0];
        if (target){ setViewer(target.url); }
      }
    }
  }catch(_){/* ignore */}
})();
</script>
</body>
</html>