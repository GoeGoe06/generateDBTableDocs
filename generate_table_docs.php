<?php
/**
 * XMLファイルからテーブル定義書（HTML/Markdown）を生成するスクリプト
 *
 * phpMyAdminやmysqldumpで出力されたXMLファイルを解析し、
 * テーブル定義書をHTML形式またはMarkdown形式で生成します。
 *
 * @author Kazunori Ishikawa <kazu.0610.i@gmail.com>
 * @copyright (c) 2025 Kazunori Ishikawa
 * @version 1.0.0
 */

try {
    // メモリ制限を512MBに設定（大容量XMLファイル対応）
    ini_set('memory_limit', '512M');

    // コマンドライン引数のチェック
    if ($argc < 2) {
        throw new InvalidArgumentException("使用方法: php generate_table_docs.php <XMLファイルパス> [--output=path] [--markdown] [--exclude=table1,table2] [--include=table1,table2]\n");
    }

    // コマンドライン引数の解析
    $xmlFile = $argv[1];  // XMLファイルパス
    $isMarkdown = in_array('--markdown', $argv);  // Markdown形式で出力するか
    $customOutputDir = null;  // カスタム出力ディレクトリ
    $excludeTables = [];  // 除外するテーブル名のリスト
    $includeTables = [];  // 含めるテーブル名のリスト（指定時は他を除外）

    // オプション引数の解析
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--exclude=')) {
            // 除外テーブルリストの設定
            $excludeTables = explode(',', substr($arg, 10));
        }
        if (str_starts_with($arg, '--include=')) {
            // 含めるテーブルリストの設定
            $includeTables = explode(',', substr($arg, 10));
        }
        if (str_starts_with($arg, '--output=')) {
            // カスタム出力ディレクトリの設定
            $customOutputDir = substr($arg, 9);
        }
    }

    if (!file_exists($xmlFile)) {
        throw new InvalidArgumentException("XMLファイルが見つかりません: $xmlFile\n");
    }

    // 出力ディレクトリの設定とセキュリティチェック
    if ($customOutputDir) {
        // カスタムディレクトリのパストラバーサル攻撃対策
        $realCustomPath = realpath(dirname($customOutputDir));
        if ($realCustomPath === false || !str_starts_with($realCustomPath, realpath(__DIR__))) {
            throw new InvalidArgumentException("無効な出力パスです: $customOutputDir\n");
        }
        $outputDir = $customOutputDir;
    } else {
        // デフォルト出力ディレクトリ（XMLファイル名ベース）
        $xmlBasename = pathinfo($xmlFile, PATHINFO_FILENAME);
        // ファイル名のサニタイズ（安全な文字のみ許可）
        $xmlBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $xmlBasename);
        $outputDir = __DIR__ . '/' . $xmlBasename;
    }

    if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
        throw new RuntimeException(sprintf('Directory "%s" was not created', $outputDir));
    }

    // XMLファイルの読み込み（メモリ効率的なXMLReaderを使用）
    $tables = [];  // テーブル情報を格納する配列
    $reader = new XMLReader();
    if (!$reader->open($xmlFile)) {
        throw new RuntimeException("XMLファイルの読み込みに失敗しました: $xmlFile\n");
    }

    // XMLファイルの解析（phpMyAdmin形式とmysqldump形式に対応）
    while ($reader->read()) {
        // phpMyAdmin XML形式の処理
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'table') {
            $tableXml = $reader->readOuterXML();
            // phpMyAdminの名前空間プレフィックスを削除
            $tableXml = str_replace('pma:', '', $tableXml);
            $tableElement = simplexml_load_string($tableXml);
            if ($tableElement && isset($tableElement['name'])) {
                $tableName = (string)$tableElement['name'];
                $createStatement = (string)$tableElement;
                $columns = parseCreateStatement($createStatement);
                if (!empty($columns)) {
                    // テーブルフィルタリング
                    if (!empty($includeTables) && !in_array($tableName, $includeTables, true)){continue;}
                    if (!empty($excludeTables) && in_array($tableName, $excludeTables, true)){continue;}
                    // テーブル情報の格納
                    $tables[$tableName] = [
                        'name' => $tableName,
                        'columns' => $columns,
                        'indexes' => extractIndexes($createStatement),
                        'partitions' => extractPartitions($createStatement),
                        'comment' => extractTableComment($createStatement)
                    ];
                }
            }
            // メモリ使用量削減のため変数をクリア
            unset($tableXml, $tableElement);
        // mysqldump XML形式の処理
        } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'table_structure') {
            $tableXml = $reader->readOuterXML();
            $tableElement = simplexml_load_string($tableXml);
            if ($tableElement && isset($tableElement['name'])) {
                $tableName = (string)$tableElement['name'];
                $columns = parseMysqldumpStructure($tableElement);
                if (!empty($columns)) {
                    // テーブルフィルタリング
                    if (!empty($includeTables) && !in_array($tableName, $includeTables, true)){ continue;}
                    if (!empty($excludeTables) && in_array($tableName, $excludeTables, true)){ continue;}
                    // テーブル情報の格納
                    $tables[$tableName] = [
                        'name' => $tableName,
                        'columns' => $columns,
                        'indexes' => parseMysqldumpIndexes($tableElement),
                        'partitions' => parseMysqldumpPartitions($tableElement),
                        'comment' => (string)($tableElement['Comment'] ?? '')
                    ];
                }
            }
            // メモリ使用量削減のため変数をクリア
            unset($tableXml, $tableElement);
        }
    }

    $reader->close();

    // 各テーブルの定義書ファイルを生成
    foreach ($tables as $tableName => $tableInfo) {
        if ($isMarkdown) {
            generateTableMarkdown($tableInfo, $outputDir);
        } else {
            generateTableHtml($tableInfo, $outputDir);
        }
    }

    // インデックスファイルの生成（--includeオプション使用時は生成しない）
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

} catch (Exception $e) {
    error_log("エラー: " . $e->getMessage());
    exit(1);
}

/**
 * CREATE TABLE文からカラム情報を抽出する
 *
 * @param string $createStatement CREATE TABLE文の文字列
 * @return array カラム情報の配列
 */
function parseCreateStatement(string $createStatement): array
{
    $columns = [];  // カラム情報を格納する配列
    // CREATE TABLE文からカラム定義部分を抽出
    if (preg_match('/CREATE TABLE.*?\((.*)\)\s*ENGINE/s', $createStatement, $matches)) {
        $columnDefs = $matches[1];
        $lines = explode("\n", $columnDefs);

        foreach ($lines as $line) {
            $line = trim($line);
            // 空行やインデックス定義行はスキップ
            if (empty($line) || str_starts_with($line, 'PRIMARY KEY') || str_starts_with($line, 'UNIQUE KEY') || str_starts_with($line, 'KEY')) {
                continue;
            }

            // カラム定義の正規表現パターン
            $columnPattern = '/^`([^`]+)`\s+([^,\s]+(?:\([^)]+\))?)\s*(.*?)(?:,\s*)?$/';
            if (preg_match($columnPattern, $line, $colMatches)) {
                if (count($colMatches) < 4) {continue;}  // マッチが不完全な場合はスキップ
                [, $columnName, $dataType, $attributes] = $colMatches;

                // カラムコメントの抽出
                $comment = '';
                $commentPattern = '/COMMENT\s+[\'"]([^\'"]*)[\'"]/';
                if (preg_match($commentPattern, $attributes, $commentMatches)) {
                    $comment = html_entity_decode($commentMatches[1], ENT_QUOTES, 'UTF-8');
                }

                // NULL許可の判定
                $nullable = !str_contains($attributes, 'NOT NULL');

                // デフォルト値の抽出
                $defaultValue = '';
                $defaultQuotedPattern = '/DEFAULT\s+[\'"]([^\'"]*)[\'"]/';
                $defaultUnquotedPattern = '/DEFAULT\s+([^\s,]+)/';
                if (preg_match($defaultQuotedPattern, $attributes, $defaultMatches)) {
                    $defaultValue = $defaultMatches[1];
                } elseif (preg_match($defaultUnquotedPattern, $attributes, $defaultMatches)) {
                    $defaultValue = $defaultMatches[1];
                }

                // AUTO_INCREMENT属性の判定
                $autoIncrement = str_contains($attributes, "AUTO_INCREMENT");
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
 * CREATE TABLE文からインデックス情報を抽出する
 *
 * @param string $createStatement CREATE TABLE文の文字列
 * @return array インデックス情報の配列
 */
function extractIndexes(string $createStatement): array
{
    $indexes = [];  // インデックス情報を格納する配列
    if (preg_match('/CREATE TABLE.*?\((.*)\)\s*ENGINE/s', $createStatement, $matches)) {
        $columnDefs = $matches[1];
        $lines = explode("\n", $columnDefs);

        foreach ($lines as $line) {
            $line = trim($line);

            // PRIMARY KEYの処理
            if (preg_match('/PRIMARY KEY\s*\(([^)]+)\)/', $line, $matches)) {
                $columns = array_map('trim', explode(',', str_replace('`', '', $matches[1])));
                $indexes[] = [
                    'name' => 'PRIMARY',
                    'type' => 'PRIMARY KEY',
                    'columns' => $columns,
                    'unique' => true
                ];
            }
            // UNIQUE KEYの処理
            elseif (preg_match('/UNIQUE KEY\s+`?([^`\s]+)`?\s*\(([^)]+)\)/', $line, $matches)) {
                $indexName = $matches[1];
                $columns = array_map('trim', explode(',', str_replace('`', '', $matches[2])));
                $indexes[] = [
                    'name' => $indexName,
                    'type' => 'UNIQUE',
                    'columns' => $columns,
                    'unique' => true
                ];
            }
            // 通常のKEYの処理
            elseif (preg_match('/KEY\s+`?([^`\s]+)`?\s*\(([^)]+)\)/', $line, $matches)) {
                $indexName = $matches[1];
                $columns = array_map('trim', explode(',', str_replace('`', '', $matches[2])));
                $indexes[] = [
                    'name' => $indexName,
                    'type' => 'INDEX',
                    'columns' => $columns,
                    'unique' => false
                ];
            }
        }
    }
    return $indexes;
}

/**
 * CREATE TABLE文からパーティション情報を抽出する
 *
 * @param string $createStatement CREATE TABLE文の文字列
 * @return array パーティション情報の配列
 */
function extractPartitions(string $createStatement): array
{
    $partitions = [];  // パーティション情報を格納する配列
    // PARTITION BY句の検索
    if (preg_match('/PARTITION BY\s+(\w+)\s*\(([^)]+)\)\s*\((.*)\)\s*$/s', $createStatement, $matches)) {
        [,$partitionType, $partitionExpression, $partitionDefs] = $matches;
//        $partitionType = $matches[1];        // パーティションタイプ（RANGE、HASH等）
//        $partitionExpression = $matches[2];  // パーティション式
//        $partitionDefs = $matches[3];        // パーティション定義部分
        
        // RANGE パーティションの処理
        if (preg_match_all('/PARTITION\s+(\w+)\s+VALUES\s+LESS\s+THAN\s*\(([^)]+)\)/', $partitionDefs, $partMatches, PREG_SET_ORDER)) {
            foreach ($partMatches as $partMatch) {
                $partitions[] = [
                    'name' => $partMatch[1],
                    'type' => $partitionType,
                    'expression' => $partitionExpression,
                    'value' => trim($partMatch[2])
                ];
            }
        }
        // HASH/KEY パーティションの処理
        elseif (preg_match('/PARTITIONS\s+(\d+)/', $partitionDefs, $partCountMatch)) {
            $partitionCount = (int)$partCountMatch[1];
            for ($i = 0; $i < $partitionCount; $i++) {
                $partitions[] = [
                    'name' => "p$i",
                    'type' => $partitionType,
                    'expression' => $partitionExpression,
                    'value' => "パーティション $i"
                ];
            }
        }
    }
    return $partitions;
}

/**
 * CREATE TABLE文からテーブルコメントを抽出する
 *
 * @param string $createStatement CREATE TABLE文の文字列
 * @return string テーブルコメント
 */
function extractTableComment(string $createStatement): string
{
    if (preg_match('/COMMENT\s*=\s*[\'"]([^\'"]*)[\'"]/', $createStatement, $matches)) {
        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

/**
 * 個別テーブルのHTMLファイルを生成する
 *
 * @param array $tableInfo テーブル情報
 * @param string $outputDir 出力ディレクトリ
 * @throws RuntimeException ファイル書き込みに失敗した場合
 */
function generateTableHtml(array $tableInfo, string $outputDir): void
{
    $tableName = $tableInfo['name'];
    $columns = $tableInfo['columns'];
    $indexes = $tableInfo['indexes'] ?? [];
    $partitions = $tableInfo['partitions'] ?? [];
    $tableComment = $tableInfo['comment'];
    
    // HTMLインジェクション対策
    $safeTableName = htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8');
    $safeTableComment = htmlspecialchars($tableComment, ENT_QUOTES, 'UTF-8');
    
    $html = "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"UTF-8\"><title>テーブル定義書 - $safeTableName</title>";
    $html .= "<style>body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5}";
    $html .= ".container{max-width:1200px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}";
    $html .= "h1{color:#333;border-bottom:3px solid #007bff;padding-bottom:10px}";
    $html .= "table{width:100%;border-collapse:collapse;margin:20px 0}";
    $html .= "th,td{border:1px solid #ddd;padding:8px;text-align:left}";
    $html .= "th{background:#007bff;color:white}";
    $html .= "tr:nth-child(even){background:#f9f9f9}";
    $html .= ".type{font-family:monospace;background:#eee;padding:2px 4px;border-radius:3px}";
    $html .= ".nullable-yes{color:#28a745;font-weight:bold}";
    $html .= ".nullable-no{color:#dc3545;font-weight:bold}";
    $html .= ".auto-increment{background:#fff3cd;color:#856404;padding:2px 6px;border-radius:3px;font-size:12px}";
    $html .= "</style></head><body><div class=\"container\">";
    $html .= "<h1>テーブル定義書: $safeTableName</h1>";
    $html .= "<p><strong>テーブル名:</strong> $safeTableName</p>";
    
    if (!empty($tableComment)) {
        $html .= "<p><strong>説明:</strong> $safeTableComment</p>";
    }
    
    $html .= "<p><strong>カラム数:</strong> " . count($columns) . "</p>";
    $html .= "<table><thead><tr><th>カラム名</th><th>データ型</th><th>NULL許可</th><th>デフォルト値</th><th>その他</th><th>説明</th></tr></thead><tbody>";

    foreach ($columns as $column) {
        $nullableText = $column['nullable'] ? '<span class="nullable-yes">YES</span>' : '<span class="nullable-no">NO</span>';
        $safeColumnName = htmlspecialchars($column['name'], ENT_QUOTES, 'UTF-8');
        $safeColumnType = htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8');
        $safeDefaultValue = htmlspecialchars($column['default'], ENT_QUOTES, 'UTF-8');
        $autoIncrementText = $column['auto_increment'] ? '<span class="auto-increment">AUTO_INCREMENT</span>' : '';
        $safeComment = htmlspecialchars($column['comment'], ENT_QUOTES, 'UTF-8');
        
        $html .= "<tr><td><strong>$safeColumnName</strong></td><td><span class=\"type\">$safeColumnType</span></td><td>$nullableText</td><td>$safeDefaultValue</td><td>$autoIncrementText</td><td>$safeComment</td></tr>";
    }
    
    $html .= "</tbody></table>";

    // インデックス情報の追加
    if (!empty($indexes)) {
        $html .= '<h2>インデックス情報</h2><table><thead><tr><th>インデックス名</th><th>タイプ</th><th>対象カラム</th><th>ユニーク</th></tr></thead><tbody>';
        
        foreach ($indexes as $index) {
            $safeIndexName = htmlspecialchars($index['name'], ENT_QUOTES, 'UTF-8');
            $safeIndexType = htmlspecialchars($index['type'], ENT_QUOTES, 'UTF-8');
            $safeIndexColumns = htmlspecialchars(implode(', ', $index['columns']), ENT_QUOTES, 'UTF-8');
            $uniqueText = $index['unique'] ? '<span class="nullable-no">YES</span>' : '<span class="nullable-yes">NO</span>';
            $html .= "<tr><td><strong>$safeIndexName</strong></td><td><span class=\"type\">$safeIndexType</span></td><td>$safeIndexColumns</td><td>$uniqueText</td></tr>";
        }
        
        $html .= '</tbody></table>';
    }
    
    // パーティション情報の追加
    if (!empty($partitions)) {
        $html .= '<h2>パーティション情報</h2><table><thead><tr><th>パーティション名</th><th>タイプ</th><th>式</th><th>値</th></tr></thead><tbody>';
        
        foreach ($partitions as $partition) {
            $safePartitionName = htmlspecialchars($partition['name'], ENT_QUOTES, 'UTF-8');
            $safePartitionType = htmlspecialchars($partition['type'], ENT_QUOTES, 'UTF-8');
            $safePartitionExpression = htmlspecialchars($partition['expression'], ENT_QUOTES, 'UTF-8');
            $safePartitionValue = htmlspecialchars($partition['value'], ENT_QUOTES, 'UTF-8');
            $html .= "<tr><td><strong>$safePartitionName</strong></td><td><span class=\"type\">$safePartitionType</span></td><td>$safePartitionExpression</td><td>$safePartitionValue</td></tr>";
        }
        
        $html .= '</tbody></table>';
    }
    
    $html .= '</div></body></html>';

    $safeFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tableName);
    $filename = $outputDir . "/" . $safeFileName . ".html";
    $result = file_put_contents($filename, $html);
    if ($result === false) {
        throw new RuntimeException("ファイルの書き込みに失敗しました: $filename");
    }
}

/**
 * インデックスHTMLファイルを生成する
 *
 * @param array $tables テーブル情報の配列
 * @param string $outputDir 出力ディレクトリ
 * @throws RuntimeException ファイル書き込みに失敗した場合
 */
function generateIndexHtml(array $tables, string $outputDir): void
{
    $tableCount = count($tables);
    $xmlBasename = htmlspecialchars(basename($outputDir), ENT_QUOTES, 'UTF-8');
    
    $html = "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>データベーステーブル定義書一覧</title>";
    
    // CSSスタイルの追加
    $html .= "<style>";
    $html .= "body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5;line-height:1.6}";
    $html .= ".container{max-width:1200px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}";
    $html .= "h1{color:#333;border-bottom:3px solid #007bff;padding-bottom:10px;margin-bottom:30px;text-align:center}";
    $html .= ".summary{background:#f8f9fa;padding:20px;border-radius:5px;margin-bottom:30px;text-align:center}";
    $html .= ".table-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px}";
    $html .= ".table-card{border:1px solid #dee2e6;border-radius:8px;padding:20px;background:white;transition:transform 0.2s,box-shadow 0.2s;text-decoration:none;color:inherit;display:block}";
    $html .= ".table-card:hover{transform:translateY(-2px);box-shadow:0 4px 15px rgba(0,0,0,0.1);text-decoration:none;color:inherit}";
    $html .= ".table-name{font-size:18px;font-weight:bold;color:#007bff;margin-bottom:10px}";
    $html .= ".table-comment{color:#6c757d;font-style:italic;margin-bottom:15px;min-height:20px}";
    $html .= ".table-stats{font-size:14px;color:#495057}";
    $html .= ".generated-time{text-align:center;color:#6c757d;font-size:14px;margin-top:30px;padding-top:20px;border-top:1px solid #dee2e6}";
    $html .= "</style></head><body>";
    
    $html .= "<div class=\"container\">";
    $html .= "<h1>データベーステーブル定義書</h1>";
    
    $html .= "<div class=\"summary\">";
    $html .= "<h2>概要</h2>";
    $html .= "<p>データベース: <strong>$xmlBasename</strong></p>";
    $html .= "<p>テーブル数: <strong>$tableCount</strong></p>";
    $html .= "</div>";
    
    $html .= "<div class=\"table-grid\">";

    foreach ($tables as $tableName => $tableInfo) {
        $safeTableName = htmlspecialchars($tableName, ENT_QUOTES, 'UTF-8');
        $safeComment = htmlspecialchars($tableInfo['comment'], ENT_QUOTES, 'UTF-8');
        $columnCount = count($tableInfo['columns']);
        $safeFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tableName);
        
        $html .= "<a href=\"$safeFileName.html\" class=\"table-card\">";
        $html .= "<div class=\"table-name\">$safeTableName</div>";
        $html .= "<div class=\"table-comment\">" . ($safeComment ?: '説明なし') . "</div>";
        $html .= "<div class=\"table-stats\">カラム数: $columnCount</div>";
        $html .= "</a>";
    }
    
    $html .= "</div>";
    
    $currentTime = date('Y-m-d H:i:s');
    $html .= "<div class=\"generated-time\">生成日時: $currentTime</div>";
    
    $html .= "</div></body></html>";

    $filename = $outputDir . "/index.html";
    $result = file_put_contents($filename, $html);
    if ($result === false) {
        throw new RuntimeException("インデックスファイルの書き込みに失敗しました: $filename");
    }
}

/**
 * 個別テーブルのMarkdownファイルを生成する
 *
 * @param array $tableInfo テーブル情報
 * @param string $outputDir 出力ディレクトリ
 * @throws RuntimeException ファイル書き込みに失敗した場合
 */
function generateTableMarkdown(array $tableInfo, string $outputDir): void
{
    $tableName = $tableInfo['name'];
    $columns = $tableInfo['columns'];
    $indexes = $tableInfo['indexes'] ?? [];
    $partitions = $tableInfo['partitions'] ?? [];
    $tableComment = $tableInfo['comment'];
    
    $markdown = "# テーブル定義書: $tableName\n\n## テーブル情報\n\n- **テーブル名**: $tableName\n";
    if (!empty($tableComment)) {
        $markdown .= "- **説明**: $tableComment\n";
    }
    $markdown .= "- **カラム数**: " . count($columns) . "\n\n## カラム一覧\n\n";
    $markdown .= "| カラム名 | データ型 | NULL許可 | デフォルト値 | その他 | 説明 |\n";
    $markdown .= "|----------|----------|----------|------------|--------|------|\n";
    
    foreach ($columns as $column) {
        $nullableText = $column['nullable'] ? 'YES' : 'NO';
        $defaultValue = $column['default'] ?: '-';
        $autoIncrementText = $column['auto_increment'] ? 'AUTO_INCREMENT' : '-';
        $comment = $column['comment'] ?: '-';
        $markdown .= "| **{$column['name']}** | `{$column['type']}` | $nullableText | $defaultValue | $autoIncrementText | $comment |\n";
    }
    
    if (!empty($indexes)) {
        $markdown .= "\n## インデックス情報\n\n| インデックス名 | タイプ | 対象カラム | ユニーク |\n|------------|------|----------|--------|\n";
        foreach ($indexes as $index) {
            $uniqueText = $index['unique'] ? 'YES' : 'NO';
            $markdown .= "| **{$index['name']}** | `{$index['type']}` | " . implode(', ', $index['columns']) . " | $uniqueText |\n";
        }
    }
    
    if (!empty($partitions)) {
        $markdown .= "\n## パーティション情報\n\n| パーティション名 | タイプ | 式 | 値 |\n|----------------|------|----|----|\n";
        foreach ($partitions as $partition) {
            $markdown .= "| **{$partition['name']}** | `{$partition['type']}` | {$partition['expression']} | {$partition['value']} |\n";
        }
    }
    
    $safeFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tableName);
    $filename = $outputDir . "/" . $safeFileName . ".md";
    $result = file_put_contents($filename, $markdown);
    if ($result === false) {
        throw new RuntimeException("Markdownファイルの書き込みに失敗しました: $filename");
    }
}

/**
 * インデックスMarkdownファイルを生成する
 *
 * @param array $tables テーブル情報の配列
 * @param string $outputDir 出力ディレクトリ
 * @throws RuntimeException ファイル書き込みに失敗した場合
 */
function generateIndexMarkdown(array $tables, string $outputDir): void
{
    $tableCount = count($tables);
    $xmlBasename = basename($outputDir);
    $markdown = "# データベーステーブル定義書\n\n## 概要\n\n- **データベース**: $xmlBasename\n- **テーブル数**: $tableCount\n\n## テーブル一覧\n\n";
    
    foreach ($tables as $tableName => $tableInfo) {
        $comment = $tableInfo['comment'] ?: '説明なし';
        $columnCount = count($tableInfo['columns']);
        $safeFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tableName);
        $markdown .= "### [$tableName]($safeFileName.md)\n\n- **説明**: $comment\n- **カラム数**: $columnCount\n\n";
    }
    
    $filename = $outputDir . "/index.md";
    $result = file_put_contents($filename, $markdown);
    if ($result === false) {
        throw new RuntimeException("Markdownインデックスファイルの書き込みに失敗しました: $filename");
    }
}

/**
 * mysqldump XML形式のテーブル構造を解析する
 *
 * @param SimpleXMLElement $tableElement テーブル要素
 * @return array カラム情報の配列
 */
function parseMysqldumpStructure(SimpleXMLElement $tableElement): array
{
    $columns = [];
    if (!isset($tableElement->field)){ return $columns;}
    
    foreach ($tableElement->field as $field) {
        $columns[] = [
            'name' => (string)$field['Field'],
            'type' => (string)$field['Type'],
            'nullable' => (string)$field['Null'] === 'YES',
            'default' => (string)($field['Default'] ?? ''),
            'auto_increment' => str_contains((string)($field['Extra'] ?? ''), 'auto_increment'),
            'comment' => (string)($field['Comment'] ?? '')
        ];
    }
    return $columns;
}

/**
 * mysqldump XML形式のインデックス情報を解析する
 *
 * @param SimpleXMLElement $tableElement テーブル要素
 * @return array インデックス情報の配列
 */
function parseMysqldumpIndexes(SimpleXMLElement $tableElement): array
{
    $indexes = [];
    if (!isset($tableElement->key)){ return $indexes;}
    
    foreach ($tableElement->key as $key) {
        $keyName = (string)$key['Key_name'];
        $nonUnique = (string)$key['Non_unique'] === '1';
        $columnName = (string)$key['Column_name'];
        
        $found = false;
        foreach ($indexes as &$index) {
            if ($index['name'] === $keyName) {
                $index['columns'][] = $columnName;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $type = $keyName === 'PRIMARY' ? 'PRIMARY KEY' : (!$nonUnique ? 'UNIQUE' : 'INDEX');
            $indexes[] = [
                'name' => $keyName,
                'type' => $type,
                'columns' => [$columnName],
                'unique' => !$nonUnique
            ];
        }
    }
    return $indexes;
}

/**
 * mysqldump XML形式のパーティション情報を解析する
 *
 * @param SimpleXMLElement $tableElement テーブル要素
 * @return array パーティション情報の配列（現在は空配列を返す）
 */
function parseMysqldumpPartitions(SimpleXMLElement $tableElement): array
{
    // mysqldumpのXMLにはパーティション情報が含まれていない場合が多い
    return [];
}