<?php
/**
 * ================================================
 * 文章 Feed API - 供 GitHub Pages 同步使用
 * ================================================
 *
 * 功能：
 * - 输出最新文章列表（JSON / XML 格式）
 * - 支持增量更新（只返回最近 N 天内的新增/修改文章）
 * - 包含完整的 SEO 元数据（title, description, keywords, url）
 *
 * 使用方式：
 * - JSON: /admin/articles_feed.php?format=json&days=7
 * - XML:  /admin/articles_feed.php?format=xml&days=7
 * - 默认: JSON 格式, 最近 7 天
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            slug,
            summary,
            description,
            keywords,
            tags,
            category,
            author,
            status,
            views,
            published_at,
            updated_at,
            created_at
        FROM articles
        WHERE status = 1
          AND (
              updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              OR published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          )
        ORDER BY published_at DESC
        LIMIT ?
    ");
    $stmt->execute([$days, $days, $limit]);
    $articles = $stmt->fetchAll();

    $siteUrl = rtrim($settings['site_url'] ?? SITE_URL, '/');

    $feedData = [
        'version' => '1.0',
        'generated_at' => date('c'),
        'site' => [
            'name' => $settings['site_name'] ?? SITE_NAME,
            'url' => $siteUrl,
        ],
        'query' => [
            'days' => $days,
            'limit' => $limit,
            'count' => count($articles),
        ],
        'articles' => array_map(function($article) use ($siteUrl) {
            return [
                'id' => (int)$article['id'],
                'title' => $article['title'],
                'slug' => $article['slug'],
                'url' => $siteUrl . '/article/' . urlencode($article['slug']),
                'description' => $article['summary'] ?: mb_substr(strip_tags($article['content'] ?? ''), 0, 160) . '...',
                'keywords' => $article['keywords'],
                'tags' => $article['tags'] ? explode(',', $article['tags']) : [],
                'category' => $article['category'],
                'author' => $article['author'] ?? '骏咖（吴亚骏）',
                'published_at' => $article['published_at'],
                'updated_at' => $article['updated_at'],
                'views' => (int)$article['views'],
            ];
        }, $articles),
    ];

    if ($format === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        echo arrayToXml($feedData, new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><feed/>'))->asXML();
    } else {
        echo json_encode($feedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function arrayToXml($array, &$xml) {
    foreach ($array as $key => $value) {
        if (is_numeric($key)) {
            $key = 'item';
        }
        if (is_array($value)) {
            $subnode = $xml->addChild($key);
            arrayToXml($value, $subnode);
        } else {
            $xml->addChild($key, htmlspecialchars((string)$value));
        }
    }
    return $xml;
}