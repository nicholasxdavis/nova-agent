<?php
// File: tool_functions.php
// Path: /api/tool_functions.php

/**
 * Main router for handling tool commands.
 */
function handle_tool_command($prompt) {
    // Set headers for a non-streaming JSON response
    header('Content-Type: application/json');

    // Parse the command and query
    list($command, $query) = array_pad(explode(' ', $prompt, 2), 2, '');
    $command = strtolower(trim($command));
    $query = trim($query);

    if (empty($query)) {
        echo json_encode(['reply' => 'Please provide a search term after the command. Example: `!wiki Albert Einstein`']);
        return;
    }

    $result = '';
    switch ($command) {
        case '!wiki':
            $result = tool_wikipedia($query);
            break;
        case '!search':
            $result = tool_duckduckgo($query);
            break;
        case '!arxiv':
            $result = tool_arxiv($query);
            break;
        case '!books':
            $result = tool_openlibrary($query);
            break;
        case '!map':
            $result = tool_openstreetmap($query);
            break;
        case '!github':
            $result = tool_github($query);
            break;
        case '!stack':
            $result = tool_stackexchange($query);
            break;
        default:
            $result = "Unknown command: `{$command}`. Available commands are: `!wiki`, `!search`, `!arxiv`, `!books`, `!map`, `!github`, `!stack`.";
            break;
    }
    
    // For tools, we send a single JSON response, not a stream.
    // The frontend will need to handle this. Let's adjust the front end to expect a stream always for simplicity.
    // Instead of json_encode, we just echo the result directly.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    echo $result;
}


// --- Tool Implementations ---

function tool_wikipedia($query) {
    $url = "https://en.wikipedia.org/w/api.php?action=query&format=json&prop=extracts&exintro=true&explaintext=true&redirects=1&titles=" . urlencode($query);
    $response = json_decode(file_get_contents($url), true);
    $pages = $response['query']['pages'];
    $page = array_shift($pages);
    if (isset($page['extract']) && !empty($page['extract'])) {
        return "#### Wikipedia Result for \"{$query}\":\n\n" . $page['extract'] . "\n\n[Read more on Wikipedia](https://en.wikipedia.org/wiki/" . urlencode($query) . ")";
    }
    return "Sorry, I couldn't find a Wikipedia article for \"{$query}\".";
}

function tool_duckduckgo($query) {
    $url = "https://api.duckduckgo.com/?q=" . urlencode($query) . "&format=json&no_html=1&skip_disambig=1";
    $response = json_decode(file_get_contents($url), true);
    if (!empty($response['AbstractText'])) {
        return "#### Quick Search Answer for \"{$query}\":\n\n" . $response['AbstractText'] . "\n\nSource: " . $response['AbstractSource'] . " ([More Details](". $response['AbstractURL'] ."))";
    }
    if(!empty($response['RelatedTopics'][0]['Text'])){
        return "#### Quick Search Answer for \"{$query}\":\n\n" . $response['RelatedTopics'][0]['Text'] . "\n\n[More Details](". $response['RelatedTopics'][0]['FirstURL'] .")";
    }
    return "Sorry, I couldn't find a quick answer for \"{$query}\".";
}

function tool_arxiv($query) {
    $url = "http://export.arxiv.org/api/query?search_query=all:" . urlencode($query) . "&start=0&max_results=3";
    $xml = simplexml_load_file($url);
    if (!$xml->entry) {
        return "Sorry, I couldn't find any research papers on arXiv for \"{$query}\".";
    }
    $output = "#### arXiv Results for \"{$query}\":\n\n";
    foreach ($xml->entry as $entry) {
        $output .= "- **" . $entry->title . "**\n";
        $output .= "  - *Authors:* " . $entry->author->name . "\n";
        $output .= "  - *Published:* " . date('Y-m-d', strtotime($entry->published)) . "\n";
        $output .= "  - [Read Paper](" . $entry->id . ")\n\n";
    }
    return $output;
}

function tool_openlibrary($query) {
    $url = "https://openlibrary.org/search.json?q=" . urlencode($query) . "&limit=3";
    $response = json_decode(file_get_contents($url), true);
    if (empty($response['docs'])) {
        return "Sorry, I couldn't find any books on Open Library for \"{$query}\".";
    }
    $output = "#### Open Library Results for \"{$query}\":\n\n";
    foreach ($response['docs'] as $doc) {
        $output .= "- **" . ($doc['title'] ?? 'N/A') . "**\n";
        $output .= "  - *Author:* " . ($doc['author_name'][0] ?? 'N/A') . "\n";
        $output .= "  - *First Published:* " . ($doc['first_publish_year'] ?? 'N/A') . "\n";
        $key = $doc['key'];
        $output .= "  - [View on Open Library](https://openlibrary.org{$key})\n\n";
    }
    return $output;
}

function tool_openstreetmap($query) {
    // This tool generates a link to a map
    return "Here is a map link for \"{$query}\":\n\n[View on OpenStreetMap](https://www.openstreetmap.org/search?query=" . urlencode($query) . ")";
}

function tool_github($query) {
    $url = "https://api.github.com/search/repositories?q=" . urlencode($query) . "&sort=stars&order=desc&per_page=3";
    $opts = ['http' => ['header' => "User-Agent: Nova-AI-Agent\r\n"]];
    $context = stream_context_create($opts);
    $response = json_decode(file_get_contents($url, false, $context), true);

    if (empty($response['items'])) {
        return "Sorry, I couldn't find any GitHub repositories for \"{$query}\".";
    }
    $output = "#### Top GitHub Repositories for \"{$query}\":\n\n";
    foreach($response['items'] as $item) {
        $output .= "- **" . $item['full_name'] . "** (⭐ " . $item['stargazers_count'] . ")\n";
        $output .= "  - " . $item['description'] . "\n";
        $output .= "  - [View on GitHub](" . $item['html_url'] . ")\n\n";
    }
    return $output;
}

function tool_stackexchange($query) {
    $url = "https://api.stackexchange.com/2.3/search/advanced?order=desc&sort=relevance&q=" . urlencode($query) . "&site=stackoverflow&filter=default&pagesize=3";
    $response = json_decode(file_get_contents($url), true);

    if(empty($response['items'])) {
        return "Sorry, I couldn't find any related questions on Stack Overflow for \"{$query}\".";
    }
    $output = "#### Top Stack Overflow Questions for \"{$query}\":\n\n";
    foreach($response['items'] as $item) {
        $output .= "- **" . $item['title'] . "** (Score: " . $item['score'] . ")\n";
        $output .= "  - [View Question](" . $item['link'] . ")\n\n";
    }
    return $output;
}

?>
