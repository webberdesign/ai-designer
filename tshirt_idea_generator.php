<?php
/*
 * PAGE NAME: tshirt_idea_generator.php
 *
 * A companion application for the T‑Shirt Design Lab. This page allows you to
 * generate creative shirt concepts (text, graphic description and color
 * palette) using the OpenAI chat API. Each idea is stored in a JSON file so
 * you can revisit it later. From the list of saved ideas you can jump straight
 * into the designer page with all the fields prepopulated, tweak them if
 * needed and then create the final artwork.
 *
 * Before using this page you should set an OpenAI API key via the
 * OPENAI_API_KEY environment variable. If the variable is not set or left
 * at its placeholder value the generator will refuse to call the API.
 */

// Load configuration for API keys and models
require_once __DIR__ . '/config.php';
// Define the OpenAI chat model constant from configuration if not already defined
if (!defined('OPENAI_CHAT_MODEL')) {
    define('OPENAI_CHAT_MODEL', $config['openai_chat_model'] ?? 'gpt-4o');
}
// Assign the OpenAI API key from configuration (leave empty if not set)
$OPENAI_API_KEY = $config['openai_api_key'] ?? '';
$IDEAS_DB_FILE  = __DIR__ . '/tshirt_ideas.json';

// Ensure the ideas database exists
if (!file_exists($IDEAS_DB_FILE)) {
    file_put_contents($IDEAS_DB_FILE, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Helper to append a new idea to the JSON DB
function save_idea(array $idea, string $dbFile)
{
    $db = json_decode(@file_get_contents($dbFile), true) ?: [];
    $db[] = $idea;
    file_put_contents($dbFile, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Endpoint: generate an idea via AJAX. Returns JSON response.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_idea') {
    header('Content-Type: application/json');
    $theme = trim($_POST['theme'] ?? '');

    // Validate that the OpenAI API key is available; instruct the user to configure it via the Config page
    global $OPENAI_API_KEY, $IDEAS_DB_FILE;
    if (!$OPENAI_API_KEY) {
        echo json_encode([
            'success' => false,
            'error'   => 'OpenAI API key is not set. Please update it on the Config page.',
        ]);
        exit;
    }

    // Build a prompt instructing the chat model to output JSON. We ask for a
    // creative slogan (text), a graphic idea, a background color and a set of
    // design colours. The theme (if provided) is passed to the model to
    // steer its creativity.
    $instruction = "You are a creative T‑shirt idea generator. Answer with a single JSON object having exactly four keys: \"text\", \"graphic\", \"bg_color\" and \"design_colors\". "
        . "The \"text\" field should be a short, catchy phrase that could appear on a shirt. "
        . "The \"graphic\" field should briefly describe an illustration that complements the text. "
        . "The \"bg_color\" field should be a hex colour code (e.g., #1A202C) representing the shirt background. "
        . "The \"design_colors\" field should be an array of two or three hex colour codes representing the colours used in the graphic and text. "
        . "Do not wrap the JSON in markdown fences or add any commentary. Just return the JSON."
        . ($theme !== '' ? " Create a concept inspired by the theme: {$theme}." : '');

    // Prepare the API request payload
    $messages = [
        ['role' => 'system', 'content' => 'You are an assistant that returns creative shirt ideas in JSON format as instructed.'],
        ['role' => 'user',   'content' => $instruction],
    ];

    $payload = [
        'model'    => OPENAI_CHAT_MODEL,
        'messages' => $messages,
        'max_tokens' => 200,
        'temperature' => 0.8,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);

    if ($status >= 400) {
        $message = $data['error']['message'] ?? 'Unknown API error';
        echo json_encode(['success' => false, 'error' => "OpenAI API error ({$status}): {$message}"]);
        exit;
    }

    // Extract the content from the chat response
    $content = $data['choices'][0]['message']['content'] ?? '';
    $idea = json_decode($content, true);
    if (!is_array($idea) || !isset($idea['text'], $idea['graphic'], $idea['bg_color'], $idea['design_colors'])) {
        echo json_encode([
            'success' => false,
            'error'   => 'The AI did not return a valid JSON idea. Ensure the model instruction is followed.',
            'raw'     => $content,
        ]);
        exit;
    }

    // Sanitise values and append metadata
    $record = [
        'id'            => uniqid('idea_', true),
        'text'          => trim($idea['text']),
        'graphic'       => trim($idea['graphic']),
        'bg_color'      => trim($idea['bg_color']),
        'design_colors' => (array)$idea['design_colors'],
        'theme'         => $theme,
        'created_at'    => date('c'),
    ];

    save_idea($record, $IDEAS_DB_FILE);

    // Return the newly created idea to the front‑end
    echo json_encode(['success' => true, 'idea' => $record]);
    exit;
}

// Load existing ideas for display
$ideas = json_decode(@file_get_contents($IDEAS_DB_FILE), true) ?: [];
$ideas = array_reverse($ideas); // Show newest first
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>T‑Shirt Idea Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Base reset */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #050816;
            color: #e5e7eb;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 24px;
        }
        .page-shell {
            width: 100%;
            max-width: 1200px;
            background: radial-gradient(circle at top left, #111827 0, #020617 55%, #020617 100%);
            border-radius: 24px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.7);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 24px;
        }
        @media (max-width: 900px) {
            .page-shell { grid-template-columns: minmax(0, 1fr); }
        }
        .page-header {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .page-title {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .pill {
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #9ca3af;
        }
        .card {
            background: rgba(15, 23, 42, 0.9);
            border-radius: 18px;
            padding: 18px 18px 20px;
            border: 1px solid rgba(55, 65, 81, 0.8);
        }
        .card-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .card-sub {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 16px;
        }
        .form-grid {
            display: grid;
            gap: 12px;
        }
        .field-label {
            font-size: 0.8rem;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .input-text {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(75, 85, 99, 0.9);
            background: rgba(15, 23, 42, 0.9);
            color: #e5e7eb;
            font-size: 0.9rem;
            outline: none;
        }
        .input-text:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.5);
        }
        .btn-row {
            margin-top: 10px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn-primary {
            border: none;
            border-radius: 999px;
            padding: 10px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #06b6d4, #6366f1);
            color: #f9fafb;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .hint {
            font-size: 0.75rem;
            color: #6b7280;
        }
        .error-banner {
            margin-top: 10px;
            font-size: 0.8rem;
            color: #fecaca;
            background: rgba(127, 29, 29, 0.6);
            border-radius: 10px;
            padding: 8px 10px;
            border: 1px solid rgba(248, 113, 113, 0.7);
        }
        /* Ideas list */
        .idea-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .idea-card {
            background: radial-gradient(circle at top, #111827 0, #020617 60%);
            border-radius: 16px;
            border: 1px solid rgba(55, 65, 81, 0.9);
            padding: 14px;
            color: #e5e7eb;
        }
        .idea-card h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .idea-card .colors {
            display: flex;
            gap: 6px;
            margin: 6px 0;
        }
        .color-swatch {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 1px solid rgba(55, 65, 81, 0.8);
        }
        .idea-actions {
            margin-top: 8px;
            display: flex;
            gap: 10px;
        }
        .idea-actions a {
            font-size: 0.75rem;
            color: #06b6d4;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="page-shell">
    <header class="page-header">
        <div>
            <div class="page-title">T‑Shirt Idea Generator</div>
            <div class="card-sub">Create and manage shirt concepts before turning them into artwork</div>
        </div>
        <div class="pill">MODEL: <?php echo htmlspecialchars(OPENAI_CHAT_MODEL); ?></div>
    </header>
    <!-- Left column: idea generator form -->
    <section class="card">
        <div class="card-title">Generate a new idea</div>
        <div class="card-sub">Optionally provide a theme to inspire the concept</div>
        <form id="idea-form" class="form-grid" method="post">
            <div>
                <label class="field-label">Theme / Topic</label>
                <input type="text" name="theme" class="input-text" placeholder="e.g., space exploration, vintage cars">
            </div>
            <div class="btn-row">
                <button type="submit" class="btn-primary" id="generate-btn">
                    <span class="icon">🎲</span>
                    <span>Generate Idea</span>
                </button>
                <span class="hint">Ideas are stored for later use</span>
            </div>
            <div id="error-banner" class="error-banner" style="display:none;"></div>
        </form>
    </section>
    <!-- Right column: ideas list -->
    <section class="card">
        <div class="card-title">Saved Ideas</div>
        <div class="card-sub">Click “Use Idea” to pre‑fill the designer form</div>
        <div class="idea-list" id="idea-list">
            <?php if (!empty($ideas)): ?>
                <?php foreach ($ideas as $idea): ?>
                    <div class="idea-card">
                        <h4><?php echo htmlspecialchars($idea['text']); ?></h4>
                        <div style="font-size:0.8rem;color:#9ca3af;">Graphic: <?php echo htmlspecialchars($idea['graphic']); ?></div>
                        <div style="font-size:0.8rem;color:#9ca3af;">BG: <span style="color:<?php echo htmlspecialchars($idea['bg_color']); ?>; font-weight:600;"><?php echo htmlspecialchars($idea['bg_color']); ?></span></div>
                        <div class="colors">
                            <?php foreach ($idea['design_colors'] as $clr): ?>
                                <span class="color-swatch" style="background: <?php echo htmlspecialchars($clr); ?>;"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="idea-actions">
                            <a href="tshirt_designer.php?text=<?php echo rawurlencode($idea['text']); ?>&graphic=<?php echo rawurlencode($idea['graphic']); ?>&bg=<?php echo rawurlencode($idea['bg_color']); ?>&colors=<?php echo rawurlencode(implode(',', $idea['design_colors'])); ?>" target="_blank">Use Idea</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="hint">No ideas yet. Generate one!</div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
/* JS for idea generation via AJAX */
(function() {
    const form = document.getElementById('idea-form');
    const generateBtn = document.getElementById('generate-btn');
    const errorBanner = document.getElementById('error-banner');
    const ideaList = document.getElementById('idea-list');

    function setError(msg) {
        if (!msg) {
            errorBanner.style.display = 'none';
            errorBanner.textContent = '';
        } else {
            errorBanner.style.display = 'block';
            errorBanner.textContent = msg;
        }
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(form);
        formData.append('action', 'generate_idea');
        setError('');
        generateBtn.disabled = true;
        generateBtn.querySelector('.icon').textContent = '⏳';
        generateBtn.querySelector('span:last-child').textContent = 'Generating…';

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                setError(data.error || 'Failed to generate idea.');
            } else {
                // Prepend the new idea to the list
                const idea = data.idea;
                const div = document.createElement('div');
                div.className = 'idea-card';
                div.innerHTML = `
                    <h4>${escapeHtml(idea.text)}</h4>
                    <div style="font-size:0.8rem;color:#9ca3af;">Graphic: ${escapeHtml(idea.graphic)}</div>
                    <div style="font-size:0.8rem;color:#9ca3af;">BG: <span style="color:${escapeHtml(idea.bg_color)}; font-weight:600;">${escapeHtml(idea.bg_color)}</span></div>
                    <div class="colors">
                        ${idea.design_colors.map(c => `<span class="color-swatch" style="background:${escapeHtml(c)};"></span>`).join('')}
                    </div>
                    <div class="idea-actions">
                        <a href="tshirt_designer.php?text=${encodeURIComponent(idea.text)}&graphic=${encodeURIComponent(idea.graphic)}&bg=${encodeURIComponent(idea.bg_color)}&colors=${encodeURIComponent(idea.design_colors.join(','))}" target="_blank">Use Idea</a>
                    </div>
                `;
                if (ideaList.firstChild) {
                    ideaList.insertBefore(div, ideaList.firstChild);
                } else {
                    ideaList.appendChild(div);
                }
            }
        })
        .catch(() => {
            setError('Network error while generating idea.');
        })
        .finally(() => {
            generateBtn.disabled = false;
            generateBtn.querySelector('.icon').textContent = '🎲';
            generateBtn.querySelector('span:last-child').textContent = 'Generate Idea';
        });
    });

    // HTML escaping helper
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();
</script>
</body>
</html>