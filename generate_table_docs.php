<?php
/**
 * XMLファイルからテーブル定義書（HTML）を生成するスクリプト
 *
 * このソフトウェアは一部に生成AIによる補助を受けて作成されています。
 * 個人利用または非営利目的での使用を許可します。
 * ただし、作者の許可なく内容の改変、再配布、または商用利用を行うことを禁じます。
 *
 * This software was created with partial assistance from AI tools.
 * Permission is granted to use this software for personal or non-commercial purposes.
 * Modification, redistribution, or commercial use without the author's permission is prohibited.
 *
 * All rights reserved.
 *
 * @author      Kazunori Ishikawa <kazu.0610.i@gmail.com>
 * @copyright   (c) 2025 Kazunori Ishikawa
 */

// メモリ制限を増加
ini_set('memory_limit', '512M');

// 引数チェック
if ($argc < 2) {
    die("使用方法: php generate_table_docs.php <XMLファイルパス> [--markdown] [--exclude=table1,table2] [--include=table1,table2]\n");
}

$xmlFile = $argv[1];
$isMarkdown = in_array('--markdown', $argv);

// 除外・指定テーブルの設定
$excludeTables = [];
$includeTables = [];
foreach ($argv as $arg) {
    if (str_starts_with( $arg, '--exclude=' )) {
        $excludeTables = explode(',', substr($arg, 10));
    }
    if (str_starts_with( $arg, '--include=' )) {
        $includeTables = explode(',', substr($arg, 10));
    }
}
if (!file_exists($xmlFile)) {
    die("XMLファイルが見つかりません: $xmlFile\n");
}

// 出力ディレクトリを作成（XMLファイル名から拡張子を除いた名前）
$xmlBasename = pathinfo($xmlFile, PATHINFO_FILENAME);
$outputDir = __DIR__ . '/' . $xmlBasename;
if (!is_dir( $outputDir ) && !mkdir( $outputDir, 0755, true ) && !is_dir( $outputDir )) {
    throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $outputDir ) );
}

// XMLを読み込み（XMLReaderを使用してメモリ効率を改善）
$tables = [];
$reader = new XMLReader();
$reader->open($xmlFile);

while ($reader->read()) {
    // phpMyAdmin XML形式
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'table') {
        $tableXml = $reader->readOuterXML();
        $tableXml = str_replace('pma:', '', $tableXml);
        $tableElement = simplexml_load_string($tableXml);
        if ($tableElement && isset($tableElement['name'])) {
            $tableName = (string)$tableElement['name'];
            $createStatement = (string)$tableElement;
            
            $columns = parseCreateStatement($createStatement);
            if (!empty($columns)) {
                if (!empty($includeTables) && !in_array( $tableName, $includeTables, true )) {continue;}
                if (!empty($excludeTables) && in_array( $tableName, $excludeTables, true )) {continue;}
                $tables[$tableName] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'comment' => extractTableComment($createStatement)
                ];
            }
        }
        unset($tableXml, $tableElement);
    }
    // mysqldump XML形式
    elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'table_structure') {
        $tableXml = $reader->readOuterXML();
        $tableElement = simplexml_load_string($tableXml);
        if ($tableElement && isset($tableElement['name'])) {
            $tableName = (string)$tableElement['name'];
            $columns = parseMysqldumpStructure($tableElement);
            if (!empty($columns)) {
                if (!empty($includeTables) && !in_array( $tableName, $includeTables, true )) {continue;}
                if (!empty($excludeTables) && in_array( $tableName, $excludeTables, true )) {continue;}
                $tables[$tableName] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'comment' => (string)($tableElement['Comment'] ?? '')
                ];
            }
        }
        unset($tableXml, $tableElement);
    }
}

$reader->close();

// 各テーブルのファイルを生成
foreach ($tables as $tableName => $tableInfo) {
    if ($isMarkdown) {
        generateTableMarkdown($tableInfo, $outputDir);
    } else {
        generateTableHtml($tableInfo, $outputDir);
    }
}

// インデックスファイルを生成（指定テーブルのみの場合は不要）
$generateIndex = empty($includeTables);
if ($generateIndex) {
    if ($isMarkdown) {
        generateIndexMarkdown($tables, $outputDir);
    } else {
        generateIndexHtml($tables, $outputDir);
    }
}

echo "テーブル定義書の生成が完了しました。\n";
echo "出力フォルダ: $outputDir\n";
echo "生成されたファイル数: " . (count($tables) + ($generateIndex ? 1 : 0)) . "\n";

/**
 * CREATE文からカラム情報を抽出
 */
function parseCreateStatement($createStatement): array
{
    $columns = [];
    
    // CREATE TABLE文を解析
    if (preg_match('/CREATE TABLE.*?\((.*)\)\s*ENGINE/s', $createStatement, $matches)) {
        $columnDefs = $matches[1];
        
        // 各行を分割
        $lines = explode("\n", $columnDefs);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with( $line, 'PRIMARY KEY' ) || str_starts_with( $line, 'UNIQUE KEY' ) || str_starts_with( $line, 'KEY' )) {
                continue;
            }
            
            // カラム定義を解析
            if (preg_match('/^`([^`]+)`\s+([^,\s]+(?:\([^)]+\))?)\s*(.*?)(?:,\s*)?$/', $line, $colMatches)) {
                [,$columnName, $dataType, $attributes] = $colMatches;

                // コメントを抽出
                $comment = '';
                if (preg_match('/COMMENT\s+[\'"]([^\'"]*)[\'"]/', $attributes, $commentMatches)) {
                    $comment = html_entity_decode($commentMatches[1], ENT_QUOTES, 'UTF-8');
                }
                
                // NULL許可を判定
                $nullable = !str_contains( $attributes, 'NOT NULL' );
                
                // デフォルト値を抽出
                $defaultValue = '';
                if (preg_match('/DEFAULT\s+[\'"]([^\'"]*)[\'"]/', $attributes, $defaultMatches)) {
                    $defaultValue = $defaultMatches[1];
                } elseif (preg_match('/DEFAULT\s+([^\s,]+)/', $attributes, $defaultMatches)) {
                    $defaultValue = $defaultMatches[1];
                }
                
                // AUTO_INCREMENTを判定
                $autoIncrement = str_contains( $attributes, "AUTO_INCREMENT" );
                $columns[] = [
                    'name' => $columnName,
                    'type' => $dataType,
                    'nullable' => $nullable,
                    'default' => $defaultValue,
                    'auto_increment' => $autoIncrement,
                    'comment' => $comment
                ];
            }
        }
    }
    
    return $columns;
}

/**
 * テーブルコメントを抽出
 */
function extractTableComment($createStatement): string
{
    if (preg_match('/COMMENT\s*=\s*[\'"]([^\'"]*)[\'"]/', $createStatement, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

/**
 * 個別テーブルのHTMLファイルを生成
 */
function generateTableHtml($tableInfo, $outputDir): void
{
    $tableName      = $tableInfo['name'];
    $columns        = $tableInfo['columns'];
    $tableComment   = $tableInfo['comment'];
    $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>テーブル定義書 - $tableName</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }
        .table-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .table-info h2 {
            margin-top: 0;
            color: #495057;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 14px;
        }
        th, td {
            border: 1px solid #dee2e6;
            padding: 12px 8px;
            text-align: left;
            vertical-align: top;
        }
        th {
            background-color: #007bff;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        tr:hover {
            background-color: #e3f2fd;
        }
        .type {
            font-family: 'Courier New', monospace;
            background-color: #e9ecef;
            padding: 2px 4px;
            border-radius: 3px;
        }
        .nullable-yes {
            color: #28a745;
            font-weight: bold;
        }
        .nullable-no {
            color: #dc3545;
            font-weight: bold;
        }
        .auto-increment {
            background-color: #fff3cd;
            color: #856404;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .back-link:hover {
            background-color: #5a6268;
        }
        .comment {
            font-style: italic;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.html" class="back-link" style="display: none;" id="backLink">← テーブル一覧に戻る</a>
        <script>
            if (document.referrer.includes('index.html') || window.location.search.includes('showBack')) {
                document.getElementById('backLink').style.display = 'inline-block';
            }
        </script>
        
        <h1>テーブル定義書: {$tableName}</h1>
        
        <div class="table-info">
            <h2>テーブル情報</h2>
            <p><strong>テーブル名:</strong> {$tableName}</p>
HTML;

    if (!empty($tableComment)) {
        $html .= "<p><strong>説明:</strong> {$tableComment}</p>";
    }
    
    $columnCount = count($columns);
    $html .= <<<HTML
            <p><strong>カラム数:</strong> {$columnCount}</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>カラム名</th>
                    <th>データ型</th>
                    <th>NULL許可</th>
                    <th>デフォルト値</th>
                    <th>その他</th>
                    <th>説明</th>
                </tr>
            </thead>
            <tbody>
HTML;

    foreach ($columns as $column) {
        $nullableText = $column['nullable'] ? '<span class="nullable-yes">YES</span>' : '<span class="nullable-no">NO</span>';
        $defaultValue = htmlspecialchars($column['default']);
        $autoIncrementText = $column['auto_increment'] ? '<span class="auto-increment">AUTO_INCREMENT</span>' : '';
        $comment = htmlspecialchars($column['comment']);
        
        $html .= <<<HTML
                <tr>
                    <td><strong>{$column['name']}</strong></td>
                    <td><span class="type">{$column['type']}</span></td>
                    <td>{$nullableText}</td>
                    <td>{$defaultValue}</td>
                    <td>{$autoIncrementText}</td>
                    <td class="comment">{$comment}</td>
                </tr>
HTML;
    }
    
    $html .= <<<HTML
            </tbody>
        </table>
    </div>
</body>
</html>
HTML;

    $filename = $outputDir . "/{$tableName}.html";
    file_put_contents($filename, $html);
}

/**
 * インデックスHTMLファイルを生成
 */
function generateIndexHtml($tables, $outputDir): void
{
    $tableCount = count($tables);
    $xmlBasename = basename(dirname($outputDir)) === 'database' ? basename($outputDir) : basename($outputDir);
    $html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>データベーステーブル定義書一覧</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
            margin-bottom: 30px;
            text-align: center;
        }
        .summary {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
            text-align: center;
        }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .table-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            background-color: #fff;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .table-name {
            font-size: 18px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
        .table-comment {
            color: #6c757d;
            font-style: italic;
            margin-bottom: 15px;
            min-height: 20px;
        }
        .table-stats {
            font-size: 14px;
            color: #495057;
        }
        .table-link {
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .table-link:hover {
            text-decoration: none;
            color: inherit;
        }
        .generated-time {
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>データベーステーブル定義書</h1>
        
        <div class="summary">
            <h2>概要</h2>
            <p>データベース: <strong>$xmlBasename</strong></p>
            <p>テーブル数: <strong>$tableCount</strong></p>
        </div>
        
        <div class="table-grid">
HTML;

    foreach ($tables as $tableName => $tableInfo) {
        $comment = htmlspecialchars($tableInfo['comment']);
        $columnCount = count($tableInfo['columns']);
        
        $html .= <<<HTML
            <a href="$tableName.html" class="table-link">
                <div class="table-card">
                    <div class="table-name">$tableName</div>
                    <div class="table-comment">$comment</div>
                    <div class="table-stats">カラム数: $columnCount</div>
                </div>
            </a>
HTML;
    }
    
    $currentTime = date('Y-m-d H:i:s');
    $html .= <<<HTML
        </div>
        
        <div class="generated-time">
            生成日時: {$currentTime}
        </div>
    </div>
</body>
</html>
HTML;

    $filename = $outputDir . "/index.html";
    file_put_contents($filename, $html);
}

/**
 * 個別テーブルのMarkdownファイルを生成
 */
function generateTableMarkdown($tableInfo, $outputDir): void
{
    $tableName      = $tableInfo['name'];
    $columns        = $tableInfo['columns'];
    $tableComment   = $tableInfo['comment'];
    
    $markdown = "# テーブル定義書: $tableName\n\n";
    
    // インデックスファイルが存在する場合のみリンクを表示
    if (file_exists($outputDir . '/index.md')) {
        $markdown .= "[← テーブル一覧に戻る](index.md)\n\n";
    }
    $markdown .= "## テーブル情報\n\n";
    $markdown .= "- **テーブル名**: $tableName\n";

    if (!empty($tableComment)) {
        $markdown .= "- **説明**: $tableComment\n";
    }
    
    $columnCount = count($columns);
    $markdown .= "- **カラム数**: $columnCount\n\n";
    $markdown .= "## カラム一覧\n\n";
    $markdown .= "| カラム名 | データ型 | NULL許可 | デフォルト値 | その他 | 説明 |\n";
    $markdown .= "|----------|----------|----------|------------|--------|------|\n";
    
    foreach ($columns as $column) {
        $nullableText = $column['nullable'] ? 'YES' : 'NO';
        $defaultValue = $column['default'] ?: '-';
        $autoIncrementText = $column['auto_increment'] ? 'AUTO_INCREMENT' : '-';
        $comment = $column['comment'] ?: '-';
        $markdown .= "| **{$column['name']}** | `{$column['type']}` | $nullableText | $defaultValue | $autoIncrementText | $comment |\n";
    }
    
    $filename = $outputDir . "/$tableName.md";
    file_put_contents($filename, $markdown);
}

/**
 * インデックスMarkdownファイルを生成
 */
function generateIndexMarkdown($tables, $outputDir): void
{
    $tableCount = count($tables);
    $xmlBasename = basename($outputDir);
    $markdown = "# データベーステーブル定義書\n\n";
    $markdown .= "## 概要\n\n";
    $markdown .= "- **データベース**: $xmlBasename\n";
    $markdown .= "- **テーブル数**: $tableCount\n\n";
    $markdown .= "## テーブル一覧\n\n";
    
    foreach ($tables as $tableName => $tableInfo) {
        $comment = $tableInfo['comment'] ?: '説明なし';
        $columnCount = count($tableInfo['columns']);
        $markdown .= "### [$tableName]($tableName.md)\n\n";
        $markdown .= "- **説明**: $comment\n";
        $markdown .= "- **カラム数**: $columnCount\n\n";
    }
    
    $currentTime = date('Y-m-d H:i:s');
    $markdown .= "---\n\n";
    $markdown .= "*生成日時: $currentTime*\n";
    
    $filename = $outputDir . "/index.md";
    file_put_contents($filename, $markdown);
}

/**
 * mysqldump XML形式のテーブル構造を解析
 */
function parseMysqldumpStructure($tableElement): array
{
    $columns = [];
    
    if (!isset($tableElement->field)) {
        return $columns;
    }
    
    foreach ($tableElement->field as $field) {
        $fieldName = (string)$field['Field'];
        $fieldType = (string)$field['Type'];
        $nullable = (string)$field['Null'] === 'YES';
        $defaultValue = (string)($field['Default'] ?? '');
        $extra = (string)($field['Extra'] ?? '');
        $comment = (string)($field['Comment'] ?? '');
        
        $autoIncrement = str_contains($extra, 'auto_increment');
        
        $columns[] = [
            'name' => $fieldName,
            'type' => $fieldType,
            'nullable' => $nullable,
            'default' => $defaultValue,
            'auto_increment' => $autoIncrement,
            'comment' => $comment
        ];
    }
    
    return $columns;
}