<?php
// File: tool_functions.php
// Path: /api/tool_functions.php

/**
 * Main router for handling tool commands.
 */
function handle_tool_command($prompt) {
    // Set headers for a non-streaming response that looks like a stream
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');

    list($command, $query) = array_pad(explode(' ', $prompt, 2), 2, '');
    $command = strtolower(trim($command, '!'));
    $query = trim($query);

    if (empty($query)) {
        echo 'Please provide a search term after the command.';
        return;
    }

    $result = '';
    switch ($command) {
        case 'wiki':
        case 'wikipedia':
            $result = tool_wikipedia($query);
            break;
        case 'search':
            $result = tool_duckduckgo($query);
            break;
        case 'arxiv':
            $result = tool_arxiv($query);
            break;
        case 'books':
            $result = tool_openlibrary($query);
            break;
        case 'github':
            $result = tool_github($query);
            break;
        case 'stack':
            $result = tool_stackexchange($query);
            break;
        default:
            $result = "Unknown command: `{$command}`.";
            break;
    }
    
    echo $result;
}

/**
 * Helper function to make GET requests with a User-Agent.
 */
function get_url_contents($url) {
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Nova-AI-Agent/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    return file_get_contents($url, false, $context);
}


// --- Tool Implementations ---

function tool_wikipedia($query) {
    $url = "https://en.wikipedia.org/w/api.php?action=query&format=json&prop=extracts&exintro=true&explaintext=true&redirects=1&titles=" . urlencode($query);
    $response_json = get_url_contents($url);
    if ($response_json === false) {
        return "Sorry, I was unable to connect to Wikipedia.";
    }
    $response = json_decode($response_json, true);
    $pages = $response['query']['pages'] ?? null;
    if (!$pages) {
        return "Sorry, I received an invalid response from Wikipedia.";
    }
    $page = array_shift($pages);
    if (isset($page['extract']) && !empty($page['extract'])) {
        return "#### Wikipedia Result for \"{$query}\":\n\n" . $page['extract'] . "\n\n[Read more on Wikipedia](https://en.wikipedia.org/wiki/" . urlencode($query) . ")";
    }
    return "Sorry, I couldn't find a Wikipedia article for \"{$query}\".";
}

function tool_duckduckgo($query) {
    $url = "https://api.duckduckgo.com/?q=" . urlencode($query) . "&format=json&no_html=1&skip_disambig=1";
    $response_json = get_url_contents($url);
     if ($response_json === false) {
        return "Sorry, I was unable to connect to the search service.";
    }
    $response = json_decode($response_json, true);
    
    if (!empty($response['AbstractText'])) {
        return "#### Quick Search Answer for \"{$query}\":\n\n" . $response['AbstractText'] . "\n\nSource: " . $response['AbstractSource'] . " ([More Details](". $response['AbstractURL'] ."))";
    }
    if(!empty($response['RelatedTopics'][0]['Text'])){
        return "#### Quick Search Answer for \"{$query}\":\n\n" . $response['RelatedTopics'][0]['Text'] . "\n\n[More Details](". $response['RelatedTopics'][0]['FirstURL'] .")";
    }
    return "Sorry, I couldn't find a quick answer for \"{$query}\". Try rephrasing your search.";
}

function tool_arxiv($query) {
    $url = "http://export.arxiv.org/api/query?search_query=all:" . urlencode($query) . "&start=0&max_results=3";
    $xml_string = get_url_contents($url);
    if ($xml_string === false) return "Sorry, I couldn't connect to arXiv.";
    
    $xml = simplexml_load_string($xml_string);
    if (!$xml->entry) {
        return "Sorry, I couldn't find any research papers on arXiv for \"{$query}\".";
    }
    $output = "#### arXiv Results for \"{$query}\":\n\n";
    foreach ($xml->entry as $entry) {
        $output .= "- **" . trim($entry->title) . "**\n";
        $authors = [];
        foreach($entry->author as $author) {
           $authors[] = trim($author->name);
        }
        $output .= "  - *Authors:* " . implode(', ', $authors) . "\n";
        $output .= "  - *Published:* " . date('Y-m-d', strtotime($entry->published)) . "\n";
        $output .= "  - [Read Paper](" . trim($entry->id) . ")\n\n";
    }
    return $output;
}

function tool_openlibrary($query) {
    $url = "https://openlibrary.org/search.json?q=" . urlencode($query) . "&limit=3";
    $response_json = get_url_contents($url);
    if($response_json === false) return "Sorry, I couldn't connect to Open Library.";
    
    $response = json_decode($response_json, true);
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

function tool_github($query) {
    $url = "https://api.github.com/search/repositories?q=" . urlencode($query) . "&sort=stars&order=desc&per_page=3";
    $response_json = get_url_contents($url);
    if($response_json === false) return "Sorry, I couldn't connect to GitHub.";

    $response = json_decode($response_json, true);
    if (empty($response['items'])) {
        return "Sorry, I couldn't find any GitHub repositories for \"{$query}\".";
    }
    $output = "#### Top GitHub Repositories for \"{$query}\":\n\n";
    foreach($response['items'] as $item) {
        $output .= "- **" . $item['full_name'] . "** (⭐ " . $item['stargazers_count'] . ")\n";
        $output .= "  - " . ($item['description'] ?? 'No description.') . "\n";
        $output .= "  - [View on GitHub](" . $item['html_url'] . ")\n\n";
    }
    return $output;
}

function tool_stackexchange($query) {
    $url = "https://api.stackexchange.com/2.3/search/advanced?order=desc&sort=relevance&q=" . urlencode($query) . "&site=stackoverflow&filter=default&pagesize=3";
    $response_json = get_url_contents($url);
    if ($response_json === false) return "Sorry, I couldn't connect to Stack Exchange.";
    
    $response = json_decode($response_json, true);
    if(empty($response['items'])) {
        return "Sorry, I couldn't find any related questions on Stack Overflow for \"{$query}\".";
    }
    $output = "#### Top Stack Overflow Questions for \"{$query}\":\n\n";
    foreach($response['items'] as $item) {
        $output .= "- **" . html_entity_decode($item['title']) . "** (Score: " . $item['score'] . ")\n";
        $output .= "  - [View Question](" . $item['link'] . ")\n\n";
    }
    return $output;
}

?>
