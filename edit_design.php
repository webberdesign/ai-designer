<?php
/*
 * PAGE: edit_design.php
 *
 * A lightweight interface for editing an existing T-shirt design using the Gemini image API. This
 * page is loaded with a query parameter `id` referencing a design stored in tshirt_designs.json.
 * It reuses much of the prompt-driven photo editor from pocket_photo_editor_software.php but
 * removes the upload functionality and preloads the selected design image as the base. Users can
 * describe edits, generate new versions, undo, or roll back. Each design has its own history
 * stored in generated/edit_designs/<design_id>/db.json.
 */

// Config for Gemini API
$API_KEY  = 'AIzaSyBO2YHmMWp7yg9kRu-srY_-B8MDs_mI8Yk'; // Replace with your Gemini API key
$MODEL    = 'gemini-2.5-flash-image';
$ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/$MODEL:generateContent";

// Ensure design ID is provided
$designId = $_GET['id'] ?? '';
if ($designId === '') {
    die('Missing design ID.');
}

// Load design record from JSON
$designs = json_decode(@file_get_contents(__DIR__ . '/tshirt_designs.json'), true) ?: [];
$design   = null;
foreach ($designs as $d) {
    if ($d['id'] === $designId) {
        $design = $d;
        break;
    }
}
if (!$design) {
    die('Design not found.');
}
// Path to original design image
$origPath = __DIR__ . '/generated_tshirts/' . $design['file'];
if (!is_file($origPath)) {
    die('Design image file is missing.');
}

// Storage: /generated/edit_designs/<designId>/
$ROOT_DIR = __DIR__ . '/generated/edit_designs/' . $designId;
$ROOT_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/generated/edit_designs/' . rawurlencode($designId);
if (!is_dir($ROOT_DIR)) {
    @mkdir($ROOT_DIR, 0755, true);
}
// DB file for this design
$DB_PATH = $ROOT_DIR . '/db.json';

// Helpers
function db_read(string $path): array {
    $json = @file_get_contents($path);
    return json_decode($json, true) ?: ['versions' => [], 'current_base_path' => null, 'original_path' => null];
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
function save_b64(string $dir, string $b64, string $mime, string $prefix='img'): string {
    $ext = ext_for_mime($mime);
    $path = rtrim($dir, '/') . '/' . safe_name($prefix, $ext);
    file_put_contents($path, base64_decode($b64));
    return $path;
}
// Call Gemini API to generate image from prompt and base
function call_api(string $key, string $endpoint, string $prompt, string $basePath, string $model): array {
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

// Bootstrapping DB and original image
$db = db_read($DB_PATH);
if (!$db['versions']) {
    // On first load copy the original design to our edit directory
    $mime = mime_content_type($origPath) ?: 'image/png';
    $ext  = ext_for_mime($mime);
    $baseFile = safe_name('orig', $ext);
    $destPath = $ROOT_DIR . '/' . $baseFile;
    @mkdir(dirname($destPath), 0755, true);
    @copy($origPath, $destPath);
    // Set DB
    $db['original_path']     = $destPath;
    $db['current_base_path'] = $destPath;
    $db['versions'] = [
        [
            'id'        => uniqid('ver_', true),
            'timestamp' => date('c'),
            'type'      => 'original',
            'path'      => $destPath,
            'url'       => $ROOT_URL . '/' . basename($destPath),
            'prompt'    => null,
            'base'      => null,
            'usage'     => null
        ]
    ];
    db_write($DB_PATH, $db);
}

// Handle AJAX endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $db = db_read($DB_PATH);
    // Edit: generate new image using current base
    if ($_POST['action'] === 'edit') {
        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') {
            echo json_encode(['ok' => 0, 'error' => 'Enter a prompt']); exit;
        }
        $base = $db['current_base_path'] ?? null;
        if (!$base || !is_file($base)) {
            echo json_encode(['ok' => 0, 'error' => 'Missing base image']); exit;
        }
        [$ok, $err, $resp] = call_api($API_KEY, $ENDPOINT, $prompt, $base, $MODEL);
        if (!$ok) {
            echo json_encode(['ok' => 0, 'error' => $err]); exit;
        }
        [$b64, $mime, $usage] = parse_image($resp);
        if (!$b64) {
            echo json_encode(['ok' => 0, 'error' => 'No image returned', 'raw' => $resp]); exit;
        }
        $out = save_b64($ROOT_DIR, $b64, $mime, 'edit');
        $ver = [
            'id'        => uniqid('ver_', true),
            'timestamp' => date('c'),
            'type'      => 'edit',
            'path'      => $out,
            'url'       => $ROOT_URL . '/' . basename($out),
            'prompt'    => $prompt,
            'base'      => $base,
            'usage'     => $usage
        ];
        array_unshift($db['versions'], $ver);
        $db['current_base_path'] = $out;
        db_write($DB_PATH, $db);
        echo json_encode(['ok' => 1, 'version' => $ver, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]); exit;
    }
    // Undo: step back to previous edit
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
    // Rollback: set base to chosen version
    if ($_POST['action'] === 'rollback') {
        $path = $_POST['path'] ?? '';
        if (!$path || !is_file($path)) {
            echo json_encode(['ok' => 0, 'error' => 'Invalid version']); exit;
        }
        $db['current_base_path'] = $path;
        db_write($DB_PATH, $db);
        echo json_encode(['ok' => 1, 'current_base' => $path]); exit;
    }
    // List: return all versions
    if ($_POST['action'] === 'list') {
        echo json_encode(['ok' => 1, 'all' => $db['versions'], 'current_base' => $db['current_base_path']]); exit;
    }
    echo json_encode(['ok' => 0, 'error' => 'Unknown action']); exit;
}

// If not an AJAX call, render the HTML interface
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Design – <?php echo htmlspecialchars($design['display_text']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
/* Theme based on pocket_photo_editor_software but simplified and stripped of upload */
:root{
  --bg:#0b0f17;
  --panel:#0f1526;
  --edge:#24355a;
  --text:#e5e9f4;
  --muted:#9aa6c5;
  --accent:#2563eb;
  --accent-dark:#1e40af;
  --pill-gradient: conic-gradient(from 0deg, #4e515a, #55585f, #4e515a, #5d6068);
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  background:linear-gradient(180deg,var(--bg),#0a0e18 60%,var(--bg));
  color:var(--text);
  font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
}
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
.card{
  background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
  border:1px solid var(--edge);
  border-radius:16px;
  box-shadow:0 12px 40px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.03);
  padding:14px;
}
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
  color:var(--text);
}
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
  <!-- Left: Viewer & History -->
  <div class="card">
    <div id="viewer" class="viewer"></div>
    <div id="errorBox" class="error" style="display:none"></div>
  </div>
  <!-- Right: History -->
  <div class="card" style="overflow:auto; max-height: calc(100vh - 200px)">
    <div style="font-weight:800; margin-bottom:10px">History</div>
    <div class="gallery" id="gallery"></div>
  </div>
</div>
<!-- Bottom Prompt Bar -->
<div class="bottomBar">
  <div class="bottomCard">
    <div class="promptRow">
      <textarea id="prompt" class="prompt" placeholder="Describe your edit… e.g., lighten background; add glow; boost contrast"></textarea>
      <div id="generateBtn" class="pill"><div class="inner">Generate</div></div>
    </div>
    <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap">
      <button id="undoBtn" class="btn">Undo</button>
      <button id="rollbackBtn" class="btn">Roll Back to Selected</button>
    </div>
  </div>
</div>
<!-- Overlay for zoom -->
<div id="overlay" class="overlay">
  <img id="overlayImg" src="" alt="Zoomed image">
</div>

<!-- JavaScript -->
<script>
/* PAGE: edit_design.php – derived from pocket_photo_editor_software.php but without upload */
// ===== State =====
let SELECTED = null; // selected version for rollback
const viewer   = document.getElementById('viewer');
const gallery  = document.getElementById('gallery');
const errorBox = document.getElementById('errorBox');
const overlay   = document.getElementById('overlay');
const overlayImg= document.getElementById('overlayImg');

function setError(msg){ errorBox.style.display='block'; errorBox.textContent=msg; }
function clearError(){ errorBox.style.display='none'; errorBox.textContent=''; }
function showSkeleton(){ viewer.innerHTML = '<div class="skeleton"></div>'; }
// Update viewer with image and update zoom link
function setViewer(url){
  viewer.innerHTML = '<img id="canvasImg" src="'+url+'" alt="Image">';
  const img = document.getElementById('canvasImg');
  img.addEventListener('click', ()=>{
    overlayImg.src = url;
    overlay.classList.add('open');
    overlayImg.dataset.scale = '1';
    overlayImg.style.transform = 'scale(1)';
  });
}
function updateGallery(items){
  gallery.innerHTML='';
  if(!items || !items.length){ gallery.innerHTML = '<div class="error">No versions yet.</div>'; return; }
  items.forEach(v => {
    const a = document.createElement('div');
    a.className = 'thumb';
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
  gallery.querySelectorAll('.thumb').forEach(t=> t.style.outline='none');
  if (el) el.style.outline='2px solid #5d6070';
}
async function postForm(formData){
  const res = await fetch(location.href, { method:'POST', body: formData });
  return await res.json();
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
      if (k.ok){
        updateGallery(k.all);
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
overlayImg.addEventListener('wheel', (e) => {
  e.preventDefault();
  const delta = Math.sign(e.deltaY);
  let scale = parseFloat(overlayImg.dataset.scale || '1');
  if (delta < 0) scale += 0.1; else scale -= 0.1;
  scale = Math.max(0.5, Math.min(5, scale));
  overlayImg.dataset.scale = scale;
  overlayImg.style.transform = 'scale(' + scale + ')';
});
// ===== Initial load =====
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