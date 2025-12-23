<?php
// Shared functions for design tools in the Merch Admin suite.
//
// This file provides helper functions to ensure storage directories and the
// database file exist, to save generated images to disk, to persist and
// fetch design records, and to call the Gemini image API. Including this
// file in each creator page helps avoid code duplication and makes it
// easier to add new tools in the future.

/**
 * Ensure that the output directory and JSON database file exist.
 * The global variables $OUTPUT_DIR and $DB_FILE must be defined by
 * the including script before calling this function.
 */
function ensure_storage(): void
{
    if (!isset($GLOBALS['OUTPUT_DIR']) || !isset($GLOBALS['DB_FILE'])) {
        // If these are not defined nothing will be created. It's up to
        // the including page to set them appropriately.
        return;
    }
    if (!is_dir($GLOBALS['OUTPUT_DIR'])) {
        mkdir($GLOBALS['OUTPUT_DIR'], 0775, true);
    }
    if (!file_exists($GLOBALS['DB_FILE'])) {
        file_put_contents($GLOBALS['DB_FILE'], json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

/**
 * Save a base64 encoded image to disk and return the filename. Returns
 * [success, errorMessage, filename]. Uses a prefix and the current date
 * and time to generate a unique name. Relies on $OUTPUT_DIR global.
 *
 * @param string $b64   The base64 data (without data URI prefix).
 * @param string $mime  The MIME type of the image (e.g. image/png).
 * @param string $prefix Prefix for the filename.
 * @return array        [bool success, string error, string filename|null]
 */
function save_image_base64(string $b64, string $mime, string $prefix = 'design'): array
{
    if (!isset($GLOBALS['OUTPUT_DIR'])) {
        return [false, 'OUTPUT_DIR is not defined.', null];
    }
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    try {
        $filename = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    } catch (Exception $e) {
        $filename = $prefix . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    }
    $filepath = rtrim($GLOBALS['OUTPUT_DIR'], '/') . '/' . $filename;
    if (file_put_contents($filepath, base64_decode($b64)) === false) {
        return [false, 'Could not save generated file.', null];
    }
    return [true, '', $filename];
}

/**
 * Append a new design record to the JSON database. Expects the record
 * array to contain at least an 'id' key. Relies on $DB_FILE global.
 *
 * @param array $record The design record to append.
 */
function save_design(array $record): void
{
    if (!isset($GLOBALS['DB_FILE'])) return;
    $db = json_decode(@file_get_contents($GLOBALS['DB_FILE']), true) ?: [];
    $db[] = $record;
    file_put_contents($GLOBALS['DB_FILE'], json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Fetch all designs filtered by a specific tool type. Returns an array of
 * design records in reverse order (most recent first). If no tool is
 * specified, returns all designs.
 *
 * @param string|null $tool The tool type to filter by (e.g. 'poster').
 * @return array            Array of design records.
 */
function get_designs_by_tool(?string $tool = null): array
{
    if (!isset($GLOBALS['DB_FILE'])) return [];
    $db = json_decode(@file_get_contents($GLOBALS['DB_FILE']), true) ?: [];
    if ($tool) {
        $filtered = array_filter($db, function ($item) use ($tool) {
            return ($item['tool'] ?? '') === $tool;
        });
    } else {
        $filtered = $db;
    }
    return array_reverse(array_values($filtered));
}

/**
 * Find a single design record by its ID. Returns null if not found.
 *
 * @param string $id Design ID to search for.
 * @return array|null The design record or null.
 */
function get_design_by_id(string $id): ?array
{
    if (!isset($GLOBALS['DB_FILE'])) return null;
    $db = json_decode(@file_get_contents($GLOBALS['DB_FILE']), true) ?: [];
    foreach ($db as $item) {
        if (($item['id'] ?? '') === $id) return $item;
    }
    return null;
}

/**
 * Find the filename of a design across all known JSON databases based on its ID.
 * This helper searches each design JSON file defined in the admin suite and
 * returns the associated file name if found. It is useful when selecting an
 * existing design as a reference image for new creations.
 *
 * @param string $id The ID of the design to locate.
 * @return string|null The file name relative to generated_tshirts/ or null if not found.
 */
function find_file_by_id(string $id): ?string
{
    $paths = [
        'tshirt'        => __DIR__ . '/tshirt_designs.json',
        'logo'          => __DIR__ . '/logo_designs.json',
        'poster'        => __DIR__ . '/poster_designs.json',
        'flyer'         => __DIR__ . '/flyer_designs.json',
        'social'        => __DIR__ . '/social_designs.json',
        'photo'         => __DIR__ . '/photo_designs.json',
        'business_card' => __DIR__ . '/business_card_designs.json',
        'certificate'   => __DIR__ . '/certificate_designs.json',
        'packaging'     => __DIR__ . '/packaging_designs.json',
        'illustration'  => __DIR__ . '/illustration_designs.json',
        'mockup'        => __DIR__ . '/mockup_designs.json',
        'vector'        => __DIR__ . '/vector_designs.json',
        'upload'        => __DIR__ . '/upload_designs.json',
        'cover'         => __DIR__ . '/cover_designs.json',
        'brochure'      => __DIR__ . '/brochure_designs.json',
        'video'         => __DIR__ . '/video_designs.json'
    ];
    foreach ($paths as $file) {
        if (!is_file($file)) continue;
        $records = json_decode(@file_get_contents($file), true);
        if (!is_array($records)) continue;
        foreach ($records as $rec) {
            if (($rec['id'] ?? '') === $id) {
                return $rec['file'] ?? null;
            }
        }
    }
    return null;
}

/**
 * Send a prompt (and optional inline image) to the Gemini API and return the
 * base64 image and MIME type. This function abstracts away the cURL call
 * details. Returns [success, mime, b64] on success or [false, error, null]
 * on failure.
 *
 * @param string $prompt    The prompt text.
 * @param array|null $inlineData Inline data with keys 'mime_type' and 'data'.
 * @param string $apiKey    Gemini API key.
 * @param string $endpoint  The full endpoint URL for generateContent.
 * @return array            [bool success, string mime|error, string b64|null]
 */
function send_gemini_image_request(string $prompt, ?array $inlineData, string $apiKey, string $endpoint): array
{
    $parts = [];
    if ($prompt !== '') {
        $parts[] = ['text' => $prompt];
    }
    if ($inlineData) {
        $parts[] = ['inline_data' => $inlineData];
    }
    $body = ['contents' => [ ['parts' => $parts] ]];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint . '?key=' . urlencode($apiKey),
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
    if ($res === false) {
        return [false, 'cURL error: ' . $err, null];
    }
    if ($code !== 200) {
        return [false, 'HTTP ' . $code . '\n' . $res, null];
    }
    $json = json_decode($res, true);
    $partsOut = $json['candidates'][0]['content']['parts'] ?? [];
    $b64 = null;
    $mime = null;
    foreach ($partsOut as $p) {
        if (isset($p['inlineData']['data'])) {
            $b64  = $p['inlineData']['data'];
            $mime = $p['inlineData']['mimeType'] ?? 'image/png';
            break;
        }
        if (isset($p['inline_data']['data'])) {
            $b64  = $p['inline_data']['data'];
            $mime = $p['inline_data']['mime_type'] ?? 'image/png';
            break;
        }
    }
    if (!$b64) {
        return [false, 'No image data returned from Gemini.', null];
    }
    return [true, $mime, $b64];
}

/**
 * Send a prompt to the OpenAI image API (gpt-image-1). Allows specifying a
 * background colour and output size. Returns [success, mime|error, b64]
 * similar to send_gemini_image_request().
 *
 * @param string      $prompt    The prompt text to send to the model.
 * @param string|null $background A background colour (e.g. '#ffffff') or 'transparent'.
 * @param string      $apiKey    The OpenAI API key.
 * @param string      $size      The desired output size (e.g. '1024x1536' or '1024x1024').
 * @return array                 [bool success, string mime|error, string b64|null]
 */
function send_openai_image_request(string $prompt, ?string $background, string $apiKey, string $size = '1024x1536', string $model = 'gpt-image-1'): array
{
    $url = 'https://api.openai.com/v1/images/generations';
    // Build payload for the OpenAI image API. Allow the model name to be provided via parameter.
    $payload = [
        'model'         => $model,
        'prompt'        => $prompt,
        'size'          => $size,
        'n'             => 1,
        'quality'       => 'high',
        // Always request PNG so transparency can be supported when using a transparent background.
        'output_format' => 'png',
    ];
    if ($background) {
        $payload['background'] = $background;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        curl_close($ch);
        return [false, 'cURL error: ' . $err, null];
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($response, true);
    if ($status >= 400) {
        $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown error';
        return [false, 'API error (' . $status . '): ' . $msg, null];
    }
    if (empty($data['data'][0]['b64_json'])) {
        return [false, 'No image data returned from OpenAI.', null];
    }
    $b64 = $data['data'][0]['b64_json'];
    // gpt-image-1 returns PNG data when output_format=png
    return [true, 'image/png', $b64];
}

/**
 * Gather a list of all design records across the various tool databases and uploads.
 * Each entry in the returned array includes an 'id', 'title', and 'file' field.
 * This helper can be used by pages that offer the ability to choose an existing
 * design as a reference image.
 *
 * @return array<int, array{id:string,title:string,file:string}> List of reference options.
 */
function get_all_reference_options(): array
{
    static $options = null;
    if ($options !== null) {
        return $options;
    }
    $options = [];
    $files = [
        __DIR__ . '/tshirt_designs.json',
        __DIR__ . '/logo_designs.json',
        __DIR__ . '/poster_designs.json',
        __DIR__ . '/flyer_designs.json',
        __DIR__ . '/social_designs.json',
        __DIR__ . '/photo_designs.json',
        __DIR__ . '/business_card_designs.json',
        __DIR__ . '/certificate_designs.json',
        __DIR__ . '/packaging_designs.json',
        __DIR__ . '/illustration_designs.json',
        __DIR__ . '/mockup_designs.json',
        __DIR__ . '/vector_designs.json',
        __DIR__ . '/upload_designs.json',
        __DIR__ . '/cover_designs.json',
        __DIR__ . '/brochure_designs.json',
        __DIR__ . '/video_designs.json'
    ];
    foreach ($files as $file) {
        if (!file_exists($file)) continue;
        $list = json_decode(@file_get_contents($file), true);
        if (!is_array($list)) continue;
        foreach ($list as $rec) {
            if (!isset($rec['file'])) continue;
            // Determine a display name
            $title = '';
            if (!empty($rec['display_text'])) {
                $title = $rec['display_text'];
            } elseif (!empty($rec['name'])) {
                $title = $rec['name'];
            } elseif (!empty($rec['title'])) {
                $title = $rec['title'];
            } elseif (!empty($rec['product_name'])) {
                $title = $rec['product_name'];
            } elseif (!empty($rec['subject'])) {
                $title = $rec['subject'];
            } else {
                $title = $rec['id'];
            }
            $options[] = [
                'id'    => $rec['id'],
                'title' => $title,
                'file'  => $rec['file']
            ];
        }
    }
    return $options;
}
