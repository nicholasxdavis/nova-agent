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

// --- System Prompt to empower the AI with Tools and Structured Output ---
$system_prompt = "You are Nova, a powerful AI assistant with access to a set of tools.

1.  **Analyze the user's request to determine if a tool is needed.**
2.  **If a tool is appropriate, you MUST respond ONLY with a JSON object specifying the tool and query.**
    -   Example: `{\"tool\": \"wikipedia\", \"query\": \"Albert Einstein\"}`
3.  **Available tools:**
    -   `wikipedia`: For factual information about topics, people, places.
    -   `search`: For general web searches, current events, or quick answers.
    -   `github`: For finding code repositories.
    -   `books`: For finding books.
    -   `stack`: For programming-related questions.
    -   `arxiv`: For scientific papers.
4.  **If the user asks for a chart or table, respond with a JSON object for that visualization.**
    -   For charts: `{\"type\": \"chart\", \"chart_type\": \"bar|line|pie\", \"data\": { ... }, \"options\": { ... }}` (Chart.js format)
    -   For tables: `{\"type\": \"table\", \"data\": [ ... ], \"columns\": [ ... ]}` (Tabulator format)
5.  **If no tool or visualization is needed, respond as a helpful AI assistant in Markdown format.**
    -   ALWAYS wrap code snippets in Markdown fences (e.g., ```python ... ```).";

// --- Step 1: Check if a tool needs to be called (Non-streaming) ---
$tool_check_response = check_for_tool_or_visualization($user_prompt, $system_prompt, $apiKey);
$decoded_response = json_decode($tool_check_response, true);

if (json_last_error() === JSON_ERROR_NONE && isset($decoded_response['tool'])) {
    // A tool was requested
    header('Content-Type: text/event-stream'); // Keep consistent with streaming
    handle_tool_command("!" . $decoded_response['tool'] . " " . $decoded_response['query']);
    exit;
} elseif (json_last_error() === JSON_ERROR_NONE && isset($decoded_response['type'])) {
    // A visualization (chart/table) was requested
    header('Content-Type: application/json');
    echo json_encode($decoded_response);
    exit;
}

// --- Step 2: If no tool, proceed with normal streaming chat ---
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
        stream_ai_response($model, $user_prompt, $system_prompt, $apiKey);
        $success = true;
        break;
    } catch (Exception $e) {
        error_log("Model $model failed: " . $e->getMessage());
    }
}

if (!$success) {
    http_response_code(503);
    echo "We're sorry, but all our AI services are currently unavailable. Please try again later.";
}

/**
 * Makes a non-streaming call to the AI to see if it wants to use a tool.
 */
function check_for_tool_or_visualization($prompt, $system_prompt, $apiKey) {
    $ch = curl_init();
    $body = [
        'model' => 'deepseek/deepseek-chat-v3.1:free',
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $prompt]
        ]
    ];
    
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://openrouter.ai/api/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://blacnova.net',
            'X-Title: Nova AI Agent'
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    return $responseData['choices'][0]['message']['content'] ?? '';
}


function stream_ai_response($model, $prompt, $system_prompt, $apiKey) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $body = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'stream' => true
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://openrouter.ai/api/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: https://blacnova.net',
            'X-Title: Nova AI Agent'
        ],
        CURLOPT_WRITEFUNCTION => function($curl, $data) {
            $lines = explode("\n", trim($data));
            foreach ($lines as $line) {
                if (strpos($line, 'data: ') === 0) {
                    $json_data = substr($line, 6);
                    if (trim($json_data) === '[DONE]') continue;

                    $chunk = json_decode($json_data, true);
                    if (isset($chunk['choices'][0]['delta']['content'])) {
                        echo $chunk['choices'][0]['delta']['content'];
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                    }
                }
            }
            return strlen($data);
        }
    ]);

    curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    curl_close($ch);
}
?>
