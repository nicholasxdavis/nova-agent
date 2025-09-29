<?php
// File: chat_handler.php
// Path: /api/chat_handler.php

// --- Environment Setup ---
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'tool_functions.php';

// --- Security & Configuration Checks ---
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$apiKey = getenv('OPENROUTER_KEY');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'API key is not configured on the server.']);
    exit;
}

// --- Input Processing ---
$data = json_decode(file_get_contents('php://input'), true);
$user_prompt = $data['prompt'] ?? '';
if (empty($user_prompt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt is empty.']);
    exit;
}

// --- System Prompt ---
$system_prompt = "You are Nova, a sophisticated and helpful AI assistant. Your primary goal is to provide direct, accurate, and intelligent responses. You have access to a variety of tools, but you should only use them when necessary.

**Core Principles:**
1.  **Prioritize Direct Answers:** For general knowledge, creative tasks, or coding, answer directly.
2.  **Intelligent Tool Usage:** Only use tools for recent or real-time information (e.g., weather, news, sports scores).
3.  **Graceful Fallbacks:** If a tool fails, say 'I'm sorry, I was unable to retrieve that information.'

**Tool Selection:**
* If a tool is needed, you MUST respond ONLY with a single, clean JSON object: `{\"tool\": \"<tool_name>\", \"query\": \"<search_query>\"}`.

**Available Tools:**
* `search`: For general web searches, current events, facts.
* `wikipedia`: For in-depth factual information.
* Other tools: `github`, `books`, `stack`, `arxiv`.

**Answering based on Tool Results:**
* When you are given data from a tool, you MUST use that data to answer the user's original question in a natural, conversational way. Do not mention the tool or the data source unless it's relevant. Synthesize the information into a final answer.";

// --- Main Logic ---

// STEP 1: Check if the AI wants to use a tool.
$initial_decision_json = make_llm_request($user_prompt, $system_prompt, $apiKey);
$decoded_decision = json_decode($initial_decision_json, true);

// Check if the decision is a valid tool call
if (json_last_error() === JSON_ERROR_NONE && isset($decoded_decision['tool'])) {
    $tool_name = $decoded_decision['tool'];
    $tool_query = $decoded_decision['query'];

    // Execute the requested tool
    $tool_result = execute_tool($tool_name, $tool_query);

    // STEP 2: Formulate a new prompt with the tool's result and get the final answer.
    $final_prompt = "Original question: '{$user_prompt}'\n\nI have retrieved the following information:\n---\n{$tool_result}\n---\nBased on this information, please provide a direct and helpful answer.";
    
    // Stream the final, synthesized answer to the user
    stream_ai_response($final_prompt, $system_prompt, $apiKey);

} else {
    // If no tool is needed, or if the initial response wasn't a valid tool JSON,
    // treat it as a direct answer and stream it.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    echo $initial_decision_json;
    flush();
}

/**
 * Executes a specified tool with a query.
 */
function execute_tool($tool_name, $query) {
    ob_start(); // Start output buffering to capture the tool's echo
    handle_tool_command("!" . $tool_name . " " . $query);
    return ob_get_clean(); // Return the captured output
}

/**
 * Makes a single, non-streaming request to the LLM.
 * Used for initial decisions or when streaming is not needed.
 */
function make_llm_request($prompt, $system_prompt, $apiKey) {
    $ch = curl_init();
    $body = [
        'model' => 'mistralai/mistral-small-3.2-24b-instruct:free', // Using a single reliable model
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 400) {
        return "Sorry, I'm currently facing connection issues with the AI service. Please try again in a moment.";
    }

    $responseData = json_decode($response, true);
    return $responseData['choices'][0]['message']['content'] ?? '';
}

/**
 * Streams a response from the LLM.
 */
function stream_ai_response($prompt, $system_prompt, $apiKey) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    $body = [
        'model' => 'mistralai/mistral-small-3.2-24b-instruct:free', // Using a single reliable model
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
    // No need to check for errors here as we are streaming directly
    curl_close($ch);
}
?>
