<?php
// File: chat_handler.php
// Path: /api/chat_handler.php

session_start();
require_once 'tool_functions.php';

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

// --- Get API Key from Environment Variable ---
$apiKey = getenv('OPENROUTER_KEY');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'API key is not configured on the server.']);
    exit;
}

// --- Get user prompt from the request ---
$data = json_decode(file_get_contents('php://input'), true);
$user_prompt = $data['prompt'] ?? '';

if (empty($user_prompt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt is empty.']);
    exit;
}

// --- AI Tool Router ---
// Check if the prompt is a command for a tool
if (strpos(trim($user_prompt), '!') === 0) {
    handle_tool_command($user_prompt);
    exit;
}

// --- AI Chat with Streaming and Fallbacks ---
$models = [
    'deepseek/deepseek-chat-v3.1:free',
    'qwen/qwen3-coder:free',
    'moonshotai/kimi-k2:free',
    'openai/gpt-oss-20b:free',
    'openai/gpt-oss-120b:free',
    'x-ai/grok-4-fast:free',
    'meta-llama/llama-3.3-8b-instruct:free',
    'google/gemma-3n-e4b-it:free',
    'mistralai/mistral-small-3.2-24b-instruct:free'
];

$success = false;
foreach ($models as $model) {
    try {
        stream_ai_response($model, $user_prompt, $apiKey);
        $success = true;
        break; 
    } catch (Exception $e) {
        // Log error or handle it, then try the next model
        error_log("Model $model failed: " . $e->getMessage());
    }
}

if (!$success) {
    // If all models fail, send a final error message
    http_response_code(503); // Service Unavailable
    echo "We're sorry, but all our AI services are currently unavailable. Please try again later.";
}

function stream_ai_response($model, $prompt, $apiKey) {
    // --- Set Headers for Streaming ---
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Important for Nginx

    $openrouter_url = 'https://openrouter.ai/api/v1/chat/completions';
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: https://blacnova.net', 
        'X-Title: Nova AI Agent'
    ];

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'stream' => true // Enable streaming
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $openrouter_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        // This function is called for each chunk of data received
        $decoded_lines = explode("\n", trim($data));
        
        foreach ($decoded_lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $json_data = substr($line, 6);
                if (trim($json_data) === '[DONE]') {
                    return strlen($data);
                }
                
                $chunk = json_decode($json_data, true);
                if (isset($chunk['choices'][0]['delta']['content'])) {
                    $content = $chunk['choices'][0]['delta']['content'];
                    echo $content;
                    
                    // --- FIX: Check if an output buffer exists before flushing ---
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }
        }
        return strlen($data);
    });

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch) || $http_code >= 400) {
        $error_msg = curl_error($ch) ?: "HTTP error code: $http_code. Response: $response";
        curl_close($ch);
        throw new Exception("cURL request failed for model $model. Error: $error_msg");
    }
    
    curl_close($ch);
}
?>
