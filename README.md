# テーブル定義書生成ツール

XMLファイルからHTMLまたはMarkdown形式のテーブル定義書を生成するPHPスクリプトです。

## 機能

- XMLファイルからテーブル構造を解析
- HTML形式またはMarkdown形式での出力
- テーブルの除外・指定機能
- メモリ効率的な大容量XMLファイル処理
- レスポンシブデザインのHTML出力

## 必要環境

- PHP 8.0以上
- XMLReader拡張

## 対応形式

- **phpMyAdmin XMLエクスポート**: 標準的なphpMyAdminのXMLエクスポート形式
- **mysqldump XML**: `mysqldump --xml` コマンドで出力されたXML形式

## 使用方法

### 基本的な使用方法

1. データベースサーバーからXML形式でmysqldumpを実行する。  
もしくはphpmyadminからXML形式でエクスポートする。
```bash
mysqldump -h [DBのホスト名] -u [DBのusername] -p [DB名] --xml --no-data > [ファイル名].xml
```

2.作成したXMLファイルをダウンロードしてスクリプトを実行
```bash
php generate_table_docs.php <XMLファイルパス> [オプション]
```

### オプション

| オプション | 説明 | 例                             |
|-----------|------|-------------------------------|
| `--markdown` | Markdown形式で出力 | `--markdown`                  |
| `--exclude=table1,table2` | 指定テーブルを除外 | `--exclude=phinxlog,sessions` |
| `--include=table1,table2` | 指定テーブルのみ出力 | `--include=companys,users`    |

## 使用例

### 全テーブルをHTML形式で出力
```bash
php generate_table_docs.php xml/db.xml
```

### 全テーブルをMarkdown形式で出力
```bash
php generate_table_docs.php xml/db.xml --markdown
```

### 特定テーブルを除外してHTML形式で出力
```bash
php generate_table_docs.php xml/db.xml --exclude=phinxlog,sessions
```

### 指定テーブルのみMarkdown形式で出力
```bash
php generate_table_docs.php xml/db.xml --include=companys,users --markdown
```

## 出力ファイル

### ファイル構成

出力先は `XMLファイル名（拡張子なし）` のフォルダに生成されます。

```
safe-navi-db/
├── index.html (または index.md)    # テーブル一覧
├── table1.html (または table1.md)   # 各テーブル定義書
├── table2.html (または table2.md)
└── ...
```

### HTML形式の特徴

- レスポンシブデザイン
- カード形式のテーブル一覧
- 色分けされたデータ型表示
- ホバー効果付きテーブル
- 戻るリンクによるナビゲーション

### Markdown形式の特徴

- GitHub等で表示可能
- テーブル形式でのカラム情報表示
- 軽量なファイルサイズ
- 相互リンクによるナビゲーション

## 生成される情報

各テーブル定義書には以下の情報が含まれます：

- テーブル名
- テーブルコメント（説明）
- カラム数
- 各カラムの詳細情報：
  - カラム名
  - データ型
  - NULL許可
  - デフォルト値
  - AUTO_INCREMENT等の属性
  - カラムコメント

## 注意事項

- `--include` オプション使用時はインデックスファイルは生成されません
- 大容量XMLファイル処理のためメモリ制限を512MBに設定
- XMLReader使用によりメモリ効率的な処理を実現

## トラブルシューティング

### メモリ不足エラーが発生する場合

```bash
php -d memory_limit=1G generate_table_docs.php xml/large-file.xml
```

### XMLファイルが見つからない場合

ファイルパスが正しいか確認してください。相対パスまたは絶対パスで指定可能です。

## 作成者

Kazunori Ishikawa <kazu.0610.i@gmail.com>

## ライセンス
このソフトウェアは一部に生成AIによる補助を受けて作成されています。  
個人利用または非営利目的での使用を許可します。  
ただし、作者の許可なく内容の改変、再配布、または商用利用を行うことを禁じます。

This software was created with partial assistance from AI tools.  
Permission is granted to use this software for personal or non-commercial purposes.  
Modification, redistribution, or commercial use without the author's permission is prohibited.

All rights reserved.