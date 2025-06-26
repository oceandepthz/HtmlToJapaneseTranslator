## PHPライブラリ仕様書: HtmlToJapaneseTranslator

### 1. ライブラリの目的

HTML文字列を受け取り、その構造を維持したまま、内部のテキストコンテンツを日本語に翻訳することに特化したPHPライブラリを提供する。

### 2. 設計思想

*   イミュータブル (Immutable): インスタンス生成後の内部状態は変更されない。`__construct` で受け取ったHTMLと設定は不変であり、各メソッドは新しい値を返す。
*   依存性の注入 (Dependency Injection): APIキーやモデル名といった外部設定は、専用の設定オブジェクト (`TranslatorConfig`) を通じてコンストラクタからインスタンスに注入（Inject）される。これにより、状態管理が明確になり、テスト容易性が向上する。

### 3. コア技術

*   翻訳エンジン: Google Gemini API を利用する。
*   HTML解析: PHPの標準機能である `DOMDocument` および `DOMXPath` を利用して、翻訳要否の判定や最終的なHTMLの整形を行う。

### 4. クラス設計と基本的な使い方

#### 4.1. 設定管理クラス: `TranslatorConfig`

APIキーとモデル名をカプセル化し、設定情報を安全に管理する。

*   クラス名: `TranslatorConfig`
*   主要メソッド:
    *   `public function __construct(string|array $apiKey, string $modelName = 'gemini-2.5-flash')`: APIキーと、任意でモデル名を受け取る。APIキーが空の場合は `LogicException` を投げる。
    *   `public function getRandomApiKey(): string`: 設定されたAPIキーの中からランダムに1つを返す。
    *   `public function getModelName(): string`: 設定されたモデル名を返す。

#### 4.2. 本体クラス: `HtmlToJapaneseTranslator`

HTMLの翻訳処理を実行するメインクラス。

*   クラス名: `HtmlToJapaneseTranslator`
*   主要メソッド:
    *   `public function __construct(string $html, TranslatorConfig $config)`: 翻訳対象のHTML文字列と、設定オブジェクト `TranslatorConfig` を受け取る。
    *   `public function needsTranslation(): bool`: HTML内のテキストが翻訳を必要とするか判定する。
    *   `public function translate(): string`: HTMLを日本語に翻訳し、新しいHTML文字列として返す。

#### 4.3. サンプルコード

```php
// 1. 設定オブジェクトを作成
// APIキーは単一文字列または配列で指定可能
$config = new TranslatorConfig('YOUR_GEMINI_API_KEY');

// (任意) デフォルト以外のモデルを使用する場合
// $config = new TranslatorConfig('YOUR_GEMINI_API_KEY', 'gemini-2.5-pro');

// 2. 翻訳したいHTMLと設定オブジェクトを渡してインスタンス化
$originalHtml = '<html><body><h1>Hello World!</h1><p>This is a pen.</p></body></html>';
$translator = new HtmlToJapaneseTranslator($originalHtml, $config); 

// 3. 翻訳が必要か判定し、必要であれば翻訳を実行
if ($translator->needsTranslation()) {
    $translatedHtml = $translator->translate();
    // -> '<html><body><h1>こんにちは、世界！</h1><p>これはペンです。</p></body></html>' (のような結果)
} else {
    $translatedHtml = $originalHtml;
}
```

### 5. 詳細なロジック

#### HTML解析における前提事項
本ライブラリは、HTMLドキュメント全体だけでなく、部分的なHTML断片（フラグメント）が入力されることを想定しています。`DOMDocument`はHTML断片をロードする際に自動的に`<html>`や`<body>`タグで補完しますが、ライブラリ内部でこの挙動を吸収します。`translate()`メソッドは、翻訳後、これらの自動付与されたタグを除去し、可能な限り元の断片構造を維持したHTML文字列を返します。また、解析時にはUTF-8での文字化け対策を講じます。

#### 5.1. 翻訳要否の判定 (`needsTranslation`)
高速性とシンプルさを重視し、以下のロジックで判定する。

1.  `DOMDocument` を利用してHTMLを解析し、タグやコメント等を除いた全てのテキストノードを連結した文字列を取得する。
2.  このテキスト文字列の先頭から最大1000文字を判定対象として切り出す。
3.  切り出した文字列に「ひらがな」または「カタカナ」が1文字でも含まれているかを正規表現 (`/[\p{Hiragana}\p{Katakana}]/u`) を用いて判定する。
    *   含まれている場合： すでに日本語コンテンツが含まれるとみなし、`false`（翻訳不要）を返す。
    *   含まれていない場合： 翻訳が必要なコンテンツの可能性があるとみなし、`true`（翻訳要）を返す。
4.  この判定ロジックの性質上、漢字のみで構成される日本語のテキスト（例: `本日休業`）や中国語のテキストは、翻訳対象として判定される。詳細は後述の「制約事項」を参照。

#### 5.2. 翻訳対象の制御（AIへの指示）
翻訳対象から除外したい要素の制御は、PHP側でHTMLからノードを削除するのではなく、システムプロンプトを通じてGemini APIに直接指示する方法を採る。これにより、AIがHTMLの全体構造を理解した上で、翻訳すべき箇所とそうでない箇所を判断することが期待される。

#### 5.3. プロンプトエンジニアリング
翻訳時にGemini APIへ与えるシステムプロンプトは、PHPのソースコードとは分離された外部ファイル（例: `prompt.txt`）に記述する。このプロンプトには、翻訳指示に加えて以下の重要な制約を含める必要がある。

*   指定されたタグ（`<script>`, `<style>`, `<pre>`, `<code>`）の内部は、翻訳せずに原文のまま出力すること。
*   入力されたHTMLの構造（タグ、属性、コメントなど）を完全に維持すること。

これらの指示をAIが正確に解釈し実行できるかは、プロンプトの品質に大きく依存する。そのため、この外部プロンプトファイルの調整が、本ライブラリの品質を左右する最も重要な要素となる。

### 6. エラーハンドリング

*   設定不備: `TranslatorConfig` のコンストラクタでAPIキーが未設定（空の文字列や空の配列）の場合、即座に `LogicException` を投げる。
*   APIエラー: `translate()` メソッド実行時に発生するAPI関連のエラー。
    *   リトライ不可能なエラー (認証エラー等): 即座にカスタム例外 `TranslationException` を投げる。
    *   リトライ可能なエラー (ネットワークの一時的な不調等): 最大5回まで再試行する。各試行の間には指数関数的バックオフなどの待機時間を設ける。
    *   リトライ失敗: 5回のリトライ全てに失敗した場合、最終的に `TranslationException` を投げる。

### 7. 制約事項

*   言語判定の精度:
    翻訳が必要かどうかの判定は、テキストに「ひらがな」または「カタカナ」が含まれるか否かという簡易的なロジックに依存している。このため、漢字のみの日本語や中国語は翻訳が必要であると判定される。

*   AIによる翻訳制御の不確実性:
    翻訳対象の制御をAIへの指示に委ねているため、プロンプトの設計に関わらず、AIが指示を100%遵守する保証はない。結果として、`<pre>`や`<code>`タグ内のコードが部分的に翻訳されたり、HTMLの属性や空白が意図せず変更されたりする可能性がある。

*   APIコストとトークン制限:
    本ライブラリは、翻訳不要な`<script>`タグなどの内容も含めてHTML全体をAPIに送信する。そのため、これらのタグ内に長大なコードが含まれている場合、APIのトークン消費量が大幅に増加し、翻訳コストが高くなる可能性がある。また、コンテンツ全体がAPIのトークン上限を超えやすくなる。

### 8. 動作環境と配布

*   サポートPHPバージョン: PHP 7.4以上
*   パッケージ管理: Composerに対応し、`composer.json` を提供する。Packagistでの公開を目指す。
