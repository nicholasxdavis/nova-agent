<?php
// File: chat_handler.php
// Path: /api/chat_handler.php

session_start();

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

// --- Prepare the API request to OpenRouter ---
$openrouter_url = 'https://openrouter.ai/api/v1/chat/completions';
$headers = [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    'HTTP-Referer: https://blacnova.net', // Replace with your actual domain
    'X-Title: Nova AI Agent' // Replace with your app name
];

$body = [
    'model' => 'deepseek/deepseek-chat-v3.1:free', // Primary model
    'messages' => [
        ['role' => 'user', 'content' => $user_prompt]
    ]
];

// --- Make the cURL request ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $openrouter_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- Process and return the response ---
if ($http_code >= 400) {
    http_response_code($http_code);
    // Forward the error from OpenRouter if available
    echo $response;
    exit;
}

$responseData = json_decode($response, true);
$ai_content = $responseData['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';

echo json_encode(['reply' => $ai_content]);

