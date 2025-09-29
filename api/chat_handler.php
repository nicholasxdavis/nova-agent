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
$context_data = $data['context'] ?? null; // For multi-step operations

if (empty($user_prompt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt is empty.']);
    exit;
}

// --- System Prompt to empower the AI with Tools and Structured Output ---
$system_prompt = "You are Nova, a powerful AI assistant with access to a set of tools. Your goal is to provide accurate, helpful responses, using tools when necessary.

**Instructions:**

1.  **Analyze the User's Request:** First, understand the user's intent. Do they want information, code, a visualization, or just a chat?

2.  **Tool Selection (Single JSON Response):**
    * If a tool is needed, you MUST respond ONLY with a single, clean JSON object specifying the tool and query. Do not add any other text.
    * **Syntax:** `{\"tool\": \"<tool_name>\", \"query\": \"<search_query>\"}`

3.  **Available Tools:**
    * `search`: For general web searches, current events, facts, or any information you don't know. This is your primary tool for information gathering.
    * `wikipedia`: For in-depth factual information about topics, people, and places.
    * `github`: **For internal use only.** Use this to find code repositories as a reference for generating code. **NEVER show the list of repositories to the user.** Instead, analyze the results internally and generate the code the user asked for in your final response.
    * `books`: For finding books.
    * `stack`: For programming-related questions.
    * `arxiv`: For scientific papers.

4.  **Visualization Workflow (Two-Step Process):**
    * **Step 1: Data Gathering.** If the user asks for a chart or table and you do not have the data, your FIRST response must be a `search` tool call to find the necessary information.
        * *Example User Prompt:* \"Show me a graph of how much people are obese in the united states.\"
        * *Your REQUIRED First Response:* `{\"tool\": \"search\", \"query\": \"obesity rates in the United States by state latest data\"}`
    * **Step 2: Data Formatting.** After the data is found, you will be re-invoked with the data provided in the context. You must then format that data into a JSON response for the requested visualization.
        * **Chart JSON:** `{\"type\": \"chart\", \"chart_type\": \"bar|line|pie\", \"data\": { ... }, \"options\": { ... }}` (Chart.js format)
        * **Table JSON:** `{\"type\": \"table\", \"data\": [ ... ], \"columns\": [ ... ]}` (Tabulator format)

5.  **Standard Chat Response:**
    * If no tool or visualization is needed, respond as a helpful AI assistant in standard Markdown format.
    * Always wrap code snippets in Markdown fences (e.g., ```python ... ```).";


// If context data is provided, it means we are in a multi-step operation (e.g., generating a chart after a search).
if ($context_data) {
    // We formulate a new prompt for the AI, giving it the context it needs to finish the job.
    $user_prompt = "Based on the following data, please fulfill the original request '{$user_prompt}'.\n\nData:\n{$context_data}";
}

// --- Step 1: Check if a tool needs to be called (Non-streaming) ---
$tool_check_response = check_for_tool_or_visualization($user_prompt, $system_prompt, $apiKey);
$decoded_response = json_decode($tool_check_response, true);

if (json_last_error() === JSON_ERROR_NONE && isset($decoded_response['tool'])) {
    $tool_name = $decoded_response['tool'];
    $tool_query = $decoded_response['query'];
    
    // This is the crucial logic for the 2-step chart generation
    if ($tool_name === 'search' && (str_contains(strtolower($user_prompt), 'chart') || str_contains(strtolower($user_prompt), 'graph') || str_contains(strtolower($user_prompt), 'plot'))) {
        $search_result = tool_search($tool_query);
        // Respond with a special type that tells the frontend to re-call with context
        header('Content-Type: application/json');
        echo json_encode([
            'type' => 'continue',
            'context' => $search_result,
            'original_prompt' => $data['prompt'] // Send the original user prompt back
        ]);
    } else {
        // A standard tool was requested
        header('Content-Type: text/event-stream');
        handle_tool_command("!" . $tool_name . " " . $tool_query);
    }
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

    $response = curl_exec($ch);
    if (curl_errno($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
        $error_msg = curl_error($ch) ?: "HTTP error: " . curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        throw new Exception($error_msg);
    }
    curl_close($ch);
}
?>

