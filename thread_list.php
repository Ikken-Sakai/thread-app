<?php
// thread_list.php
/*
【概要】
このページは掲示板の「スレッド一覧画面」を表示するメイン画面。
JavaScriptが非同期通信 (fetch API) を用いて `api.php` にアクセスし、
スレッド一覧の取得、返信投稿・削除・編集などを動的に処理する。

【主な処理構成】
1. PHP部（上部）
   - ログインチェックとユーザー名の取得。
   - HTMLの基本構造を出力。

2. JavaScript部（下部）
   - fetch APIを利用してサーバーと非同期通信。
   - スレッド一覧の表示、返信投稿・削除・編集・ソート・ページネーションなどを実装。
   - DOM（Document Object Model）を直接操作して画面内容を更新。

【セキュリティ対策】
- PHP側：
    - `require_login()` による未ログインユーザーのアクセス制限。
    - `htmlspecialchars()` による出力エスケープ（XSS防止）。
- JavaScript側：
    - `escapeHTML()` による出力時エスケープ（XSS防止）。
    - `confirm()` による削除確認。
    - fetch通信時のエラーハンドリング・入力バリデーション。

【通信フロー】
ブラウザ (JavaScript)
   ↓  fetch()（GET:一覧 / POST:投稿・削除など）
   →  api.php（GET/POSTでデータ取得・更新）
   ←  JSON形式の応答（スレッド・返信・結果メッセージなど）
   ↓
   DOMに反映（HTMLを動的に生成して表示）
*/


require_once __DIR__ . '/auth.php';
require_login(); // ログインしていない場合はlogin.phpにリダイレクト
?>


<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>スレッド一覧</title>
    <link rel="stylesheet" href="style_thread.css">
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    <div class="container">
        <h1>スレッド一覧</h1>


        <div class="nav-links">
            <p><?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>さんとしてログイン中</p>
            <a href="logout.php" class="btn btn-secondary">ログアウト</a>
            <a href="new_thread.php" class="btn btn-primary">新規投稿</a>
            <a href="profile_list.php" class="btn btn-secondary">プロフィール一覧へ</a>
            <button id="refreshBtn" class="btn btn-secondary">↻</button>

            <div class="sort-controls-inline">
                <select id="sortSelect" class="sort-select">
                    <option value="created_at_desc">新しい順</option>
                    <option value="created_at_asc">古い順</option>
                    <option value="updated_at_desc">更新順</option>
                </select>
            </div>
        </div>

        
        <p id="loading-message" aria-live="polite"></p>

        <div id="thread-list"></div>
        <div id="pagination" class="pagination"></div>
    </div>


    <script>
        // 初期設定と共通変数
        const API_ENDPOINT = 'api.php'; // API呼び出し先

        /**
         * fetch() の共通ラッパー関数。
         * - セッションタイムアウト(401 Unauthorized)時はログインページへリダイレクト。
         * - 通常の通信エラー (404/500 など) は呼び出し元で処理。
         * - 全てのAPI呼び出しでこの関数を利用し、重複コードを防ぐ。
         */
        async function apiFetch(url, options) {
            const response = await fetch(url, options);

            // 応答が401 Unauthorizedなら、セッション切れと判断
            if (response.status === 401) {
                alert('セッションタイム切れのため、ログイン画面にもどります');
                window.location.href = 'login.php'; // ログインページにリダイレクト
                
                //エラーをthrowする代わりに、後続の処理を停止させる
                return new Promise(() => {});
            }

            return response; // 正常な場合はそのままレスポンスを返す
        }

        // HTML要素を取得
        const $loadingMessage = document.getElementById('loading-message');
        const $threadList = document.getElementById('thread-list'); //HTMLの箱(掲示板全体)
        const $refreshBtn = document.getElementById('refreshBtn'); // 更新ボタン要素を取得

        //PHPからログイン中のユーザ情報を取得しJavascript変数に埋め込み
        const LOGGED_IN_USERNAME = "<?= htmlspecialchars($_SESSION['user']['username'], ENT_QUOTES, 'UTF-8') ?>";
        //ログイン中のユーザIDも保持
        let loggedInUserId = null;

        // 現在のソート順とページ番号を保持する変数
        let currentSort = 'created_at'; // デフォルト: 作成日時
        let currentOrder = 'desc';      // デフォルト: 降順
        let currentPage = 1;            // デフォルト: 1ページ目

        // スレッド一覧取得と表示
        async function fetchAndDisplayThreads() {
            $loadingMessage.textContent = 'スレッドを読み込み中...';
            try {
                //APIエンドポイントにソートとページパラメータを追加
                const url = `${API_ENDPOINT}?sort=${currentSort}&order=${currentOrder}&page=${currentPage}`;
                const response = await apiFetch(url); //apiにGETリクエスト送信、一覧取得

                if (!response.ok) {
                    throw new Error(`HTTPエラー: ${response.status}`);
                }
                // APIからのレスポンス(オブジェクト)を一旦 data 変数で受け取る
                const data = await response.json(); 
                // グローバル変数にログインユーザーIDを保存
                loggedInUserId = data.current_user_id; 

                //ページ情報の取得とUI更新を追加
                const totalPages = data.totalPages || 1; // APIから総ページ数を取得 (なければ1)
                const receivedPage = data.currentPage || 1; // APIから現在のページ番号を取得 (なければ1)
                currentPage = receivedPage; // currentPageをAPIからの値で更新

                if (Array.isArray(data.threads)) {
                     displayThreads(data.threads);
                     // ページネーションUIを更新
                     updatePaginationUI(totalPages, currentPage); 
                } else {
                     console.error('API応答の data.threads が配列ではありません:', data.threads);
                     displayThreads([]); 
                     updatePaginationUI(0, 1); // エラー時はページネーションもクリア
                }

                //成功したら、更新しましたメッセージ表示
                $loadingMessage.textContent = '一覧を更新しました。';
                // メッセージがまだ「更新しました」の場合のみ消す
                // (連続クリックなどで「読み込み中」に変わっていたら消さない)
                if ($loadingMessage.textContent === '一覧を更新しました。') {
                    $loadingMessage.textContent = '';
                }
            } catch (error) {
                $loadingMessage.textContent = '';
                $threadList.innerHTML = `<p class="error">読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        // スレッド一覧をHTMLとして表示
        /**
         * スレッド一覧を受け取り、HTML構造を動的に生成して画面に表示する関数。
         * 各スレッドは以下の要素で構成される：
         *  - タイトル、投稿者名、本文、投稿日時
         *  - 「返信一覧表示」ボタン・「編集」「削除」ボタン
         *  - 返信フォーム（テキストエリア＋送信ボタン）
         * 
         * 各要素は innerHTML を使用して一括生成し、
         * 最後にスレッド一覧コンテナへ appendChild() で追加する。
         */
        function displayThreads(threads) {
            $threadList.innerHTML = '';
            if (!Array.isArray(threads) || threads.length === 0) {
                $threadList.innerHTML = '<p>まだ投稿がありません。</p>';
                return;
            }

            //threadsを1つづつ取り出し
            threads.forEach(thread => {
                const threadElement = document.createElement('div');
                threadElement.className = 'thread-item';

                // 各スレッドのHTMLテンプレート。返信フォームを追加
                // 自分の投稿かどうかを判定 (APIから取得したログインIDと比較)
                const isOwner = (thread.user_id === loggedInUserId);
                
            threadElement.innerHTML = `
                <div class="thread-header">
                    <span class="thread-meta">投稿者: ${escapeHTML(thread.username)}</span>
                    </div>
                    <div class="thread-header-right">
                    <span class="thread-date">${thread.created_at}</span>
                    ${thread.updated_at && thread.updated_at !== thread.created_at
                        ? `<small class="edited-label">（編集済み: ${thread.updated_at}）</small>`
                        : ''}
                    </div>
                </div>

                <hr class="title-divider"> 
                <div class="thread-title-line">${escapeHTML(thread.title)}</div>

                <div class="thread-body">
                    <p>${escapeHTML(thread.body)}</p>
                </div>

                <div class="thread-info">
                    <div class="thread-info-left">
                    <button class="btn show-replies-btn" data-thread-id="${thread.id}" data-reply-count="${thread.reply_count}">
                        返信${thread.reply_count}件
                    </button>
                    </div>
                    <div class="action-buttons">
                    ${thread.user_id === loggedInUserId ? `
                        <button class="btn btn-edit" data-href="edit_post.php?id=${thread.id}">編集</button>
                        <button class="btn btn-delete delete-btn" data-post-id="${thread.id}">削除</button>
                    ` : ''}
                    </div>
                </div>

                <hr class="divider">

                <div class="replies-container" id="replies-for-${thread.id}" style="display: none;"></div>

                <form class="reply-form" data-parent-id="${thread.id}">
                    <textarea name="body" placeholder="返信を入力..." required rows="2"></textarea>
                    <button type="submit" class="btn btn-reply">返信する</button>
                </form>
                `;



            //DOMに追加
            $threadList.appendChild(threadElement);
        }); 
            // ループですべてのスレッドを描画し終わった後に、ボタンの準備を一度だけ行う
            setupReplyButtons();
            // 返信フォームの準備を行う関数を呼び出す
            setupReplyForms();
        }

        // ソートセレクト設定
        function setupSortButtons() {
            const sortSelect = document.getElementById('sortSelect');
            if (!sortSelect) return;

            sortSelect.addEventListener('change', () => {
                const selectedValue = sortSelect.value.trim(); // "created_at_desc" など
                const parts = selectedValue.split('_');

                // created_at_asc → ["created", "at", "asc"]
                const orderBy = parts.pop();              // 最後の要素（asc/desc）
                const sortBy = parts.join('_');           // 残りを結合 → "created_at"

                currentSort = sortBy;
                currentOrder = orderBy;
                currentPage = 1;

                //console.log(`選択値: ${selectedValue}`);
                //console.log(`sort=${currentSort}, order=${currentOrder}`);
                //console.log(`送信URL: ${API_ENDPOINT}?sort=${currentSort}&order=${currentOrder}&page=${currentPage}`);

                localStorage.setItem('thread_sort', selectedValue); //ソート設定を localStorage に保存

                fetchAndDisplayThreads(); // 再読み込み
            });
        }



        // ページネーション作成
        /**
         * ページネーションのUIを生成・表示する関数
         * @param {number} totalPages - 総ページ数
         * @param {number} currentPage - 現在のページ番号
         */
        function updatePaginationUI(totalPages, currentPage) {
            const $pagination = document.getElementById('pagination');

            $pagination.innerHTML = ''; // まず中身を空にする

            // 「前へ」リンク (1ページ目じゃなければ表示)
            if (currentPage > 1) {
                $pagination.appendChild(createPageLink('« 前へ', currentPage - 1));
            }

            // ページ番号リンク (簡易版：全ページ表示)
            // (ページ数が多い場合は「...」で省略するロジックが必要になることも)
            for (let i = 1; i <= totalPages; i++) {
                $pagination.appendChild(createPageLink(i, i, i === currentPage));
            }

            // 「次へ」リンク (最終ページじゃなければ表示)
            if (currentPage < totalPages) {
                $pagination.appendChild(createPageLink('次へ »', currentPage + 1));
            }
        }

        // 返信関連（表示・投稿）
        /**
         * ページネーションのリンク要素（<a>または<strong>）を作成するヘルパー関数
         * @param {string|number} label - リンクの表示テキスト
         * @param {number} page - リンク先のページ番号
         * @param {boolean} isCurrent - 現在のページかどうか (trueなら強調表示)
         * @returns {HTMLElement} - 生成されたリンク要素
         */
        function createPageLink(label, page, isCurrent = false) {
            // 現在のページ番号はリンクではなく強調表示 (<strong>)
            if (isCurrent) {
                const strong = document.createElement('strong');
                strong.textContent = label;
                strong.style.margin = '0 5px'; // 見た目の調整
                strong.style.padding = '5px 8px';
                return strong;
            }
            
            // それ以外のページ番号はクリック可能なリンク (<a>)
            const link = document.createElement('a');
            link.href = '#'; // ページ遷移を防ぐため # を指定
            link.textContent = label;
            link.style.margin = '0 5px'; // 見た目の調整
            link.style.padding = '5px 8px';
            link.addEventListener('click', (event) => {
                event.preventDefault(); // デフォルトのリンク動作を無効化
                if (currentPage !== page) { // 現在のページと同じリンクは無視
                    console.log(`ページ移動: ${page}ページ目へ`);
                    currentPage = page; // 現在のページ番号を更新
                    fetchAndDisplayThreads(); // スレッド一覧を再取得
                }
            });
            return link;
        }

        /**
         * ページ上の全ての「返信〇件」ボタンにクリックイベントを設定する関数
         */
        function setupReplyButtons() {
            document.querySelectorAll('.show-replies-btn').forEach(button => {
                const newButton = button.cloneNode(true);
                button.replaceWith(newButton);
                newButton.addEventListener('click', () => {
                    fetchAndDisplayReplies(newButton.dataset.threadId);
                });
            });
        }

        /**
         * 特定スレッドの返信一覧を取得・表示する。
         * @param {string} parentPostId - 親スレッドのID
         * @param {boolean} forceOpen - true の場合、閉じる動作を無効化し常に開く。
         *    （削除・投稿後にも強制的に開いたまま再描画する目的で使用）
         */

        async function fetchAndDisplayReplies(parentPostId, forceOpen = false) {
            const repliesContainer = document.getElementById(`replies-for-${parentPostId}`);
            const button = document.querySelector(`[data-thread-id='${parentPostId}']`);

            // forceOpen=false のときだけトグル処理を行う（開閉切り替え）
            if (!forceOpen && repliesContainer.style.display === 'block') {
                // 閉じる前に最新の返信数を取得してボタンの件数を更新
                try {
                    const countRes = await apiFetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, {
                        cache: "no-store" // キャッシュを無効化して最新データを取得
                    });
                    if (countRes.ok) {
                        const countData = await countRes.json();
                        const replies = countData.replies || countData; // データ形式に対応
                        const replyCount = countData.count || replies.length; // 件数を取得

                        // 最新の件数をボタンに反映
                        button.dataset.replyCount = replyCount;
                        button.textContent = `返信${replyCount}件`;
                    } else {
                        // 通信エラー時は古い件数をそのまま使う
                        const replyCount = button.dataset.replyCount;
                        button.textContent = `返信${replyCount}件`;
                    }
                } catch {
                    // 通信例外が発生した場合も古い件数をそのまま表示
                    const replyCount = button.dataset.replyCount;
                    button.textContent = `返信${replyCount}件`;
                }

                // 返信一覧を非表示にして終了
                repliesContainer.style.display = 'none';
                return;
            }


            repliesContainer.innerHTML = '<p>返信を読み込み中...</p>';
            repliesContainer.style.display = 'block';

            try {
                const response = await apiFetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, {
                    cache: "no-store"
                });
                if (!response.ok) throw new Error(`HTTPエラー: ${response.status}`);

                // APIが {count, replies} 形式でも単純配列でも動作するように
                const data = await response.json();
                const replies = data.replies || data;
                const replyCount = data.count || replies.length;

                repliesContainer.innerHTML = '';

                // 件数をボタンに反映（削除後でも即更新される）
                button.dataset.replyCount = replyCount;
                button.textContent = replyCount === 0 ? '返信0件' : '返信を隠す';

                if (replyCount === 0) {
                    repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                    return;
                }
                const MAX_VISIBLE = 2; //3件以上は省略
                // forceOpen（true=全件表示）なら全件、falseなら最新2件だけ
                const visibleReplies = (forceOpen || replies.length <= MAX_VISIBLE)
                    ? replies
                    : replies.slice(-MAX_VISIBLE);


                // 返信の描画
                visibleReplies.forEach(reply => {
                    repliesContainer.appendChild(createReplyElement(reply));
                });

                // 「すべての返信を表示」ボタン（件数非表示）
                if (!forceOpen && replies.length > MAX_VISIBLE) {
                    const showAllBtn = document.createElement('button');
                    showAllBtn.textContent = 'すべての返信を表示';
                    showAllBtn.className = 'show-all-btn';

                    showAllBtn.addEventListener('click', async () => {
                        try {
                            const newResponse = await apiFetch(`${API_ENDPOINT}?parent_id=${parentPostId}&_=${Date.now()}`, { cache: "no-store" });
                            const newData = await newResponse.json();
                            const latestReplies = newData.replies || newData;

                            repliesContainer.innerHTML = '';
                            latestReplies.forEach(reply => {
                                repliesContainer.appendChild(createReplyElement(reply));
                            });

                            // ボタン削除（2重押下防止）
                            showAllBtn.remove();

                        } catch (error) {
                            repliesContainer.innerHTML = `<p class="error">再読み込みに失敗しました: ${error.message}</p>`;
                        }
                    });

                    repliesContainer.prepend(showAllBtn);
                }

                // 返信表示中にボタンのテキストを変更
                button.textContent = '返信を隠す';

            } catch (error) {
                repliesContainer.innerHTML = `<p class="error">返信の読み込みに失敗しました: ${error.message}</p>`;
            }
        }

        /**
         * 返信一件分のHTML要素を生成するヘルパー関数
         * @param {object} reply - 返信データ（body, username, created_atを含む）
         * @returns {HTMLElement} - 生成されたdiv要素
         */
        function createReplyElement(reply) {
            const replyElement = document.createElement('div'); //返信を囲む<dvi>要素を作成
            replyElement.className = 'reply-item'; //cssクラス名

            // 返信の所有者か判定 (loggedInUserIdはグローバル変数)
            //loggedInUserIdがnullでないことも確認
            const isReplyOwner = (loggedInUserId !== null && reply.user_id === loggedInUserId);

            // 改行を<br>に変換した上で、XSS対策を保持
            const formattedBody = escapeHTML(reply.body).replace(/\n/g, '<br>');

            //escapeHTMLを通してXSS攻撃対策
            replyElement.innerHTML = `
                <p>${formattedBody}</p>
                <div class="reply-meta">
                    <div class="reply-left">
                        <span>投稿者: ${escapeHTML(reply.username)}</span>
                    </div>
                    <div class="reply-right">
                        <div class="reply-right-top">
                            ${reply.updated_at && reply.updated_at !== reply.created_at
                                ? `<small class="edited-label">（編集済み）</small>`
                                : ''}
                            <span class="reply-date">投稿日時: ${reply.created_at}</span>
                        </div>
                        <div class="reply-right-buttons">
                            ${reply.user_id === loggedInUserId ? `
                                <button class="btn btn-sm btn-secondary edit-reply-btn" data-reply-id="${reply.id}">編集</button>
                                <button class="btn btn-sm btn-danger delete-btn reply-delete-btn" data-post-id="${reply.id}">削除</button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            // 編集用に、元の改行(\n)を含むテキストをdata属性に保存
            const bodyP = replyElement.querySelector('p');
            if (bodyP) {
                bodyP.dataset.rawBody = reply.body;
            }
            return replyElement;
        }

        // 返信送信
        /**
         * ページ上の全ての返信フォームにイベントを設定する関数
         */
        function setupReplyForms() {
            document.querySelectorAll('.reply-form').forEach(form => {
                form.addEventListener('submit', submitReply);
            });
        }

        /**
         * 返信フォームが送信されたときに実行される処理。
         * - APIにPOST送信 → DBに新規返信登録。
         * - 成功時：返信一覧を最新状態で再表示し、入力欄をリセット。
         * - エラー時：アラート表示＋送信ボタンを復元。
         */

        async function submitReply(event) {
            event.preventDefault(); // ページのリロードを防止

            const form = event.target;
            const textarea = form.querySelector('textarea');
            const submitButton = form.querySelector('button');
            const parentId = form.dataset.parentId;

            submitButton.disabled = true;
            submitButton.textContent = '送信中...';

            try {
                //APIにPOST送信（bodyとparentpost_idを送る）
                // api.php の POST 内「(C)返信投稿処理」が実行される
                const response = await apiFetch(API_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        body: textarea.value,
                        parentpost_id: parentId
                    })
                });

            let result;
            try {
                result = await response.json();
            }catch (err) {
                console.error('JSON解析エラー:', err);
                result = { error: `HTTPエラー: ${response.status}` };
            }
            if (!response.ok) throw new Error(result.error || `HTTPエラー: ${response.status}`);


                // (2) 返信送信が完了したら、返信欄を自動で開く
                const repliesContainer = document.getElementById(`replies-for-${parentId}`);
                repliesContainer.style.display = 'block'; // 非表示なら開く

                // (3) 返信一覧を最新状態に更新（DBから再取得）
                await fetchAndDisplayReplies(parentId, true);

                // (4) 件数ボタンのカウントを更新
                const replyCountButton = document.querySelector(`button[data-thread-id='${parentId}']`);
                replyCountButton.textContent = '返信を隠す'; // 常に開いた状態で表示

                // (5) 入力欄をリセット
                textarea.value = '';

            }catch (err) {
                console.error('JSON解析エラー:', err);
                result = { error: `HTTPエラー: ${response.status}` };


            } finally {
                // (6) ボタンの状態を戻す
                submitButton.disabled = false;
                submitButton.textContent = '返信する';
            }
        }
    
        /**
         * ページ全体 (document) にクリックイベントを設定し、
         * 「削除」「編集」ボタンが押されたときのみ対応する処理を呼び出す。
         * 
         * ※「イベントデリゲーション(Event Delegation)」というテクニックを利用。
         *   → 動的に追加されたボタン（fetch後に生成された要素）でも確実に反応する。
         */

        document.addEventListener('keydown', (e) => {
            const textarea = e.target.closest('.reply-form textarea');
            if (!textarea) return; // 返信フォーム以外なら無視

            // Ctrl+Enter or Shift+Enter → 改行を許可
            if ((e.ctrlKey || e.metaKey || e.shiftKey) && e.key === 'Enter') {
                return; // 通常の改行をそのまま通す
            }

            // Enter単体で送信
            if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault(); // 改行を防ぐ

                const form = textarea.closest('.reply-form');
                const button = form.querySelector('button[type="submit"]');
                if (button && !button.disabled) {
                    button.click(); // 返信ボタンを自動クリック
                }
            }
        });


        // 投稿削除
        /**
         * ページ全体にクリックイベントを設定し、
         * 「削除」ボタンが押されたときのみ削除処理を呼び出す。
         * （イベントデリゲーションで、新しく生成されたボタンにも対応）
         */
        // ページ全体にクリックイベントを設定し、
        // 「削除」「編集」ボタンが押されたときに対応
        document.addEventListener('click', async (e) => {
            
            // 削除ボタン (.delete-btn) の処理 (スレッド本体・返信 共通)
            if (e.target.classList.contains('delete-btn')) {
                const button = e.target;
                const postId = button.dataset.postId;
                
                // 1. セッションチェックを先に行う
                try {
                    await apiFetch('api.php?action=check_session');

                    // 2. セッションが有効なら確認ダイアログを表示
                    if (confirm('本当にこの投稿を削除しますか？')) {
                        // 3. OKなら削除処理 (confirm抜き) を実行
                        deletePost(postId, button); // ステップ1で修正したdeletePostを呼び出す
                    }
                    // キャンセルなら何もしない
                } catch (error) {
                    // (apiFetchがセッション切れ（401）を処理します)
                    console.error("Session check failed:", error);
                    if (error.message !== 'Session expired') {
                        alert("エラーが発生しました: " + error.message);
                    }
                }
            }

            // メインスレッドの編集ボタン (.btn-edit) の処理
            if (e.target.classList.contains('btn-edit')) {
                e.preventDefault(); // 念のためデフォルトの動作を停止
                
                const destinationUrl = e.target.dataset.href; // data-hrefからURLを取得
                if (!destinationUrl) return;

                try {
                    // 1. セッションが有効かチェック
                    await apiFetch('api.php?action=check_session');
                    
                    // 2. セッションが有効なら、編集ページへ遷移
                    window.location.href = destinationUrl;

                } catch (error) {
                    // (apiFetchがセッション切れ（401）を処理します)
                    console.error("Session check failed:", error);
                    if (error.message !== 'Session expired') {
                        alert("エラーが発生しました: " + error.message);
                    }
                }
            }
        });
        /**
         * 投稿を削除する処理を行う非同期関数
         * @param {string} postId - 削除する投稿のID
         * @param {HTMLElement} buttonElement - クリックされた削除ボタン要素
         */
        async function deletePost(postId, buttonElement) {
            //処理中はボタンを無効化、テキストを変更
            buttonElement.disabled = true;         // 二重クリック防止のためボタンを無効化
            buttonElement.textContent = '削除中...'; // ユーザーに処理中であることを表示

            try {
                //APIに削除リクエスト送信
                const response = await apiFetch(API_ENDPOINT, { // APIに非同期通信で削除リクエストを送る
                    method: 'POST',                          // POSTメソッドを使用
                    headers: { 'Content-Type': 'application/json' }, // JSON形式で送信
                    body: JSON.stringify({
                        action: 'delete', //APIに削除セクションと伝える
                        id: postId        //APIに削除対象のIDを伝える
                    })
                });

                const result = await response.json(); // APIからの応答をJSONとして取得
                if (!response.ok) throw new Error(result.error || `HTTPエラー: ${response.status}`); // エラー時は例外を投げる

                // 返信かどうか判定
                const isReply = buttonElement.classList.contains('reply-delete-btn'); // 返信削除ボタンならtrue

                // DOMから削除
                const postElement = buttonElement.closest(isReply ? '.reply-item' : '.thread-item'); // 投稿または返信のHTML要素を探す
                if (postElement) postElement.remove(); // 画面上から該当の投稿を削除

                // 返信削除時は件数ボタンを更新
                if (isReply) {  
                    const parentThreadItem = buttonElement.closest('.thread-item');   // 削除された返信の親スレッド要素を取得  
                    if (!parentThreadItem) return; // ←親スレッドが見つからない場合は処理中断  

                    const replyCountButton = parentThreadItem.querySelector('.show-replies-btn'); // 「返信○件」ボタンを取得  
                    if (!replyCountButton) return; // ←ボタンが存在しない場合は処理中断 

                    const currentCount = parseInt(replyCountButton.dataset.replyCount || '0', 10); // 現在の返信数を数値として取得（なければ0）  
                    const newCount = Math.max(currentCount - 1, 0);  // 返信を1減らし、0未満にならないように調整  

                    replyCountButton.dataset.replyCount = newCount;  // 新しい返信数をデータ属性に反映  

                    // ここで強制的に再描画（表示状態も維持）
                    const parentId = replyCountButton.dataset.threadId;
                    const repliesContainer = parentThreadItem.querySelector('.replies-container');

                    if (parentId && repliesContainer) {
                        repliesContainer.style.display = 'block'; // 非表示にならないように強制表示
                        repliesContainer.innerHTML = '<p>更新中...</p>'; // ローディング表示
                        await fetchAndDisplayReplies(parentId, true); // 最新状態に再描画

                        // 削除後に「全件表示ボタン」が残っていたら確実に削除
                        const allBtn = repliesContainer.querySelector('.show-all-btn');
                        if (allBtn) allBtn.remove();
                    }

                    // 返信が0件なら「まだ返信がありません」を表示
                    if (newCount === 0) {
                        const repliesContainer = parentThreadItem.querySelector('.replies-container');
                        if (repliesContainer) {
                            repliesContainer.innerHTML = '<p>この投稿にはまだ返信がありません。</p>';
                        }
                    }
                }

                alert('削除しました。'); // 成功メッセージを表示
            } catch (error) {
                alert('エラー: ' + error.message); // エラー発生時はアラートで通知
            
            //エラーが発生したら元の状態に
            } finally {
                buttonElement.disabled = false;     // ボタンを再び有効化
                buttonElement.textContent = '削除'; // ボタンの表示を元に戻す
            }
        }

        /**
         * 編集中テキストエリアでのキー操作
         * Enter        → 保存
         * Ctrl+Enter   → 改行
         * Shift+Enter  → 改行
         * Esc          → 編集キャンセル
         *
         * ※ 投稿編集・返信編集のどちらでも共通で動作。
         */


        // 返信本文をクリックしたら直接編集モードにする（オプション）
        document.addEventListener('click', (e) => {
            const bodyP = e.target.closest('.reply-item p');
            if (!bodyP) return;

            // すでにtextareaがあれば何もしない
            if (bodyP.closest('.reply-item').querySelector('.edit-textarea')) return;

            const replyDiv = bodyP.closest('.reply-item');
            const editBtn = replyDiv.querySelector('.edit-reply-btn');
            if (editBtn) {
                editBtn.click(); // 既存の編集ボタン処理を流用
            }
        });


        // ▼ 返信の編集// ▼ 返信の編集処理（非同期）
        // ページ全体でクリックを監視し、動的に生成されたボタンに対応（イベント委任）
        document.addEventListener('click', async function (e) {
            
            // 返信の編集ボタン (.edit-reply-btn) が押されたか判定
            if (e.target.classList.contains('edit-reply-btn')) {
                
                // リンクやフォームのデフォルト動作を停止
                e.preventDefault(); 

                try {
                    // 1. まずセッションが有効かチェック
                    // (セッション切れの場合、apiFetch関数内でアラートとリダイレクトが発生)
                    await apiFetch('api.php?action=check_session');

                    // 2. セッションが有効なら、編集UIの準備
                    const replyDiv = e.target.closest('.reply-item'); // 返信全体を囲むDIV
                    const replyId = e.target.dataset.replyId;         // 編集対象のID
                    const bodyP = replyDiv.querySelector('p');        // 本文<p>タグ

                    // 既に編集モード（textareaが作られている）なら、二重処理を防ぐ
                    if (replyDiv.querySelector('.edit-textarea')) {
                        return;
                    }
                    
                    // <p>タグの表示テキスト(textContent)ではなく、
                    // data属性に保存した「改行(\n)を含む」元のテキストを取得
                    const oldText = bodyP.dataset.rawBody; 

                    // <p>タグを<textarea>に置き換える
                    const textarea = document.createElement('textarea');
                    textarea.value = oldText; // これで改行が<textarea>に反映される
                    textarea.classList.add('edit-textarea');
                    bodyP.replaceWith(textarea); // <p> が <textarea> に入れ替わる
                    textarea.focus(); // 1回のクリックで入力可能


                    // 「保存」ボタンを動的に作成
                    const saveBtn = document.createElement('button');
                    saveBtn.textContent = '保存';
                    saveBtn.classList.add('btn', 'btn-sm', 'btn-primary');
                    e.target.after(saveBtn);  // 「編集」ボタンの直後に「保存」を配置

                    // 編集ボタンを非表示にする
                    e.target.style.display = 'none';


                    // --- 「保存」ボタンが押された時の処理 ---
                    saveBtn.addEventListener('click', async () => {
                        // <textarea>から新しいテキストを取得
                        const newText = textarea.value.trim();
                        
                        // 空欄チェック
                        if (!newText) {
                            alert('本文を入力してください');
                            return;
                        }

                        // (保存時のAPI通信は apiFetch を使用)
                        try {
                            // APIに「返信編集」をリクエスト
                            const res = await apiFetch(API_ENDPOINT, { 
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    action: 'edit_reply',
                                    reply_id: replyId,
                                    body: newText
                                })
                            });

                            const result = await res.json(); // APIからの結果
                            
                            // 編集成功時
                            if (result.success) {
                                // <textarea>を、更新後の本文<p>タグに置き換える
                                const newBody = document.createElement('p');
                                // new_bodyはXSS対策済みなので textContent で安全に設定
                                // APIから返された「エスケープ済みの本文」を取得
                                const escapedBody = result.new_body; 

                                // \n (改行コード) を <br> (HTMLタグ) に変換
                                const formattedBody = escapedBody.replace(/\n/g, '<br>');

                                // textContent ではなく innerHTML で設定
                                newBody.innerHTML = formattedBody;

                                textarea.replaceWith(newBody); // <textarea> が <p> に入れ替わる

                                // 編集用に、元の「\n」を含むテキストをdata属性に保存
                                // (api.phpでtrim()されているため、newTextもtrim()されたものを使用)
                                const newText = textarea.value.trim();
                                newBody.dataset.rawBody = newText;

                                // 「（編集済み）」ラベルを表示
                                const editedLabel = document.createElement('small');
                                editedLabel.classList.add('edited-label');
                                editedLabel.textContent = '（編集済み）';

                                // 投稿日時の前に追加
                                const replyRight = replyDiv.querySelector('.reply-right');
                                if (replyRight) {
                                    const dateSpan = replyRight.querySelector('.reply-date');
                                    // ラベルがまだ無ければ追加
                                    if (dateSpan && !replyRight.querySelector('.edited-label')) {
                                        dateSpan.before(editedLabel);
                                    }
                                }

                                // UIを元に戻す
                                saveBtn.remove(); // 「保存」ボタンを削除
                                e.target.style.display = 'inline-block'; // 編集ボタンを再表示
                            
                            // 編集失敗時
                            } else {
                                alert(result.error || '更新に失敗しました');
                            }
                        
                        // 保存時の通信エラー
                        } catch (err) {
                            // (apiFetchがセッション切れを処理)
                            if (err.message !== 'Session expired') {
                                console.error('通信エラー:', err);
                                alert('サーバー通信に失敗しました');
                            }
                        }
                    }); //-- 「保存」ボタンの処理ここまで --

                // 編集開始時のセッションチェックエラー
                } catch (error) {
                    // (apiFetchがセッション切れ（401）を処理します)
                    console.error("Session check failed:", error);
                    if (error.message !== 'Session expired') {
                        alert("エラーが発生しました: " + error.message);
                    }
                }
            } 
        }); 


        // ===============================
        // 編集中のキー操作（Enter保存 / Ctrl+Enter改行 / Escキャンセル）
        // ===============================
        document.addEventListener('keydown', (e) => {
            const textarea = e.target.closest('.edit-textarea');
            if (!textarea) return; // 編集中以外は無視

            const replyDiv = textarea.closest('.reply-item');
            const saveBtn = replyDiv.querySelector('.btn-primary');
            const editBtn = replyDiv.querySelector('.edit-reply-btn');

            // Ctrl+Enter or Shift+Enter なら改行（そのまま挿入して処理終了）
            if ((e.ctrlKey || e.metaKey || e.shiftKey) && e.key === 'Enter') {
                return; // 通常の改行を許可（preventDefaultしない）
            }

            // Enter 単体で保存
            if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault(); // 改行を無効化
                if (saveBtn) {
                    saveBtn.click(); // 保存ボタンを押したのと同じ動作
                }
            }

            // Esc でキャンセル（元の状態に戻す）
            if (e.key === 'Escape') {
                e.preventDefault();
                const oldText = textarea.dataset.oldText || textarea.value; // 編集前のテキストを取得

                const newP = document.createElement('p');
                // 改行を維持して戻す（\n → <br> に変換）
                newP.innerHTML = escapeHTML(oldText).replace(/\n/g, '<br>');
                newP.dataset.rawBody = oldText;

                textarea.replaceWith(newP);
                if (saveBtn) saveBtn.remove();
                if (editBtn) editBtn.style.display = 'inline-block'; // 編集ボタンを再表示
            }
        });

        /**
         * HTML特殊文字を安全に変換して画面に表示するための関数。
         * 
         * 【目的】XSS（クロスサイトスクリプティング）防止。
         * 例）<script> や & などを無害化し、テキストとして表示する。
         */
        function escapeHTML(str) {
            return str ? String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]) : '';
        }
        
        /**
         * ページのDOM構造が完全に読み込まれた後に実行される初期化処理。
         * - 前回のソート設定を localStorage から復元。
         * - スレッド一覧を初回表示。
         * - ソートセレクトのイベントを有効化。
         */

        if ($refreshBtn) { // ボタン要素が確実に見つかった場合のみ設定
             $refreshBtn.addEventListener('click', () => {
                 fetchAndDisplayThreads(); // スレッド一覧を再読み込み
             });
        }

        //ページ読み込み時に実行される処理
        document.addEventListener('DOMContentLoaded', () => {

            const savedSort = localStorage.getItem('thread_sort');

            // ソートセレクト要素を取得
            const sortSelect = document.getElementById('sortSelect');

            // 保存済みの設定があり、セレクトボックスが存在する場合のみ処理
            if (savedSort && sortSelect) {
                sortSelect.value = savedSort; // 画面上のセレクトボックスを前回の設定に戻す

                // 値を分解して、ソート項目と昇順・降順をそれぞれ取り出す
                // 例: "created_at_desc" → sortBy="created_at", orderBy="desc"
                const parts = savedSort.split('_');
                const orderBy = parts.pop();  // 最後の要素（"asc"または"desc"）
                const sortBy = parts.join('_'); // 残りを結合して "created_at" などにする

                // 現在のソート条件を設定
                currentSort = sortBy;
                currentOrder = orderBy;
            }

            // スレッド一覧を読み込み・表示
            fetchAndDisplayThreads();

            // ソートセレクトのイベントを有効化（選択変更で並び替え可能に）
            setupSortButtons();
        });

    </script>
</body>
</html>