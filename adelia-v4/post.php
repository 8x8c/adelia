<?php
declare(strict_types=1);

// ----------------------------------------------------------------------
// post.php
// ----------------------------------------------------------------------

// Admin/Mod Passwords
define('CHESSIB_ADMINPASS', "aaa");  // Full board access
define('CHESSIB_MODPASS',   "mmm");  // Moderator access (optional)

// Board Identity (display only)
define('CHESSIB_BOARD', "b");
define('CHESSIB_BOARDDESC', "/b/ - Random");

// Behavior / Appearance
define('CHESSIB_REQMOD', "disable");
define('CHESSIB_THREADSPERPAGE', 5);
define('CHESSIB_PREVIEWREPLIES', 3);
define('CHESSIB_TRUNCATE', 900);

// Flood / Limit
define('CHESSIB_DELAY', 5);
define('CHESSIB_MAXTHREADS', 100);
define('CHESSIB_MAXREPLIES', 0);

// File Types
define('CHESSIB_PIC', true);
define('CHESSIB_WEBM', true);

// File Control
define('CHESSIB_MAXKB', 10240);
define('CHESSIB_MAXKBDESC', "10 MB");

// Thumbnail dimensions
define('CHESSIB_MAXWOP', 250);
define('CHESSIB_MAXHOP', 250);
define('CHESSIB_MAXW',   250);
define('CHESSIB_MAXH',   250);

// Tripcode seed
define('CHESSIB_TRIPSEED', "some_random_string_for_tripcodes");

// Database (SQLite3) settings – updated location
define('CHESSIB_DBMODE', "sqlite3");
define('CHESSIB_DBNAME', "db/chessib.db");
define('CHESSIB_DBPOSTS', "posts");

// Optional logo HTML
define('CHESSIB_LOGO', "");

// ----------------------------------------------------------------------
// Initialization
// ----------------------------------------------------------------------
error_reporting(E_ALL);
ini_set("display_errors", "1");
session_start();
ob_implicit_flush(true);

// Ensure required directories exist
$writedirs = ["res", "src", "thumb"];
foreach ($writedirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir);
    }
    if (!is_writable($dir)) {
        exit("Directory '{$dir}' is not writable. Fix permissions or create it.");
    }
}

/**
 * Utility Functions
 */
function fancyDie(string $message): never {
    exit(
        '<body><p>' . $message . '</p><p><a href="javascript:history.go(-1)">Back</a></p></body>'
    );
}

function cleanString(string $string): string {
    return str_replace(["<", ">"], ["&lt;", "&gt;"], $string);
}

function plural(string $singular, int $count, string $plural = 's'): string {
    if ($plural === 's') {
        $plural = $singular . $plural;
    }
    return ($count === 1 ? $singular : $plural);
}

function convertBytes(int $number): string {
    $len = strlen((string)$number);
    return match (true) {
        $len < 4 => sprintf("%dB", $number),
        $len <= 6 => sprintf("%0.2fKB", $number / 1024),
        $len <= 9 => sprintf("%0.2fMB", $number / (1024 ** 2)),
        default => sprintf("%0.2fGB", $number / (1024 ** 3))
    };
}

function nameAndTripcode(string $name): array {
    if (preg_match("/(#|!)(.*)/", $name, $regs)) {
        $cap = $regs[2];
        if (function_exists('mb_convert_encoding')) {
            $recoded_cap = mb_convert_encoding($cap, 'SJIS', 'UTF-8');
            if ($recoded_cap !== '') {
                $cap = $recoded_cap;
            }
        }
        $cap_delimiter = (strpos($name, '#') !== false) ? '#' : '!';
        $is_secure_trip = false;

        if (preg_match("/(.*)($cap_delimiter)(.*)/", $cap, $regs_secure)) {
            $cap       = $regs_secure[1];
            $cap_secure = $regs_secure[3];
            $is_secure_trip = true;
        }
        $tripcode = "";
        if ($cap !== "") {
            $cap = strtr($cap, "&amp;", "&");
            $cap = strtr($cap, "&#44;", ", ");
            $salt = substr($cap . "H.", 1, 2);
            $salt = preg_replace("/[^\.-z]/", ".", $salt);
            $salt = strtr($salt, ":;<=>?@[\\]^_`", "ABCDEFGabcdef");
            $tripcode = substr(crypt($cap, $salt), -10);
        }
        if ($is_secure_trip) {
            if ($cap !== "") {
                $tripcode .= "!";
            }
            $tripcode .= "!" . substr(md5($cap_secure . CHESSIB_TRIPSEED), 2, 10);
        }
        $nameonly = preg_replace("/($cap_delimiter)(.*)/", "", $name);
        return [$nameonly, $tripcode];
    }
    return [$name, ""];
}

function nameBlock(
    string $name,
    string $tripcode,
    string $email,
    int $timestamp,
    string $rawposttext
): string {
    $output = '<span class="postername">' . ($name === '' ? 'Anonymous' : $name) . '</span>';
    if ($tripcode !== '') {
        $output .= '<span class="postertrip">!' . $tripcode . '</span>';
    }
    $time_str = date('m/d/y (D) H:i:s', $timestamp);
    return $output . $rawposttext . ' ' . $time_str;
}

/**
 * SQLite3 Database Functions
 */
function dbConnect(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(CHESSIB_DBNAME);
        $db->busyTimeout(5000);
        // Enforce WAL mode on connection
        $db->exec("PRAGMA journal_mode=WAL;");
    }
    return $db;
}

function newPost(int $parent = 0): array {
    return [
        'id'                  => 0,
        'parent'              => $parent,
        'timestamp'           => 0,
        'bumped'              => 0,
        'ip'                  => '',
        'name'                => '',
        'tripcode'            => '',
        'email'               => '',
        'nameblock'           => '',
        'subject'             => '',
        'message'             => '',
        'password'            => '',
        'file'                => '',
        'file_hex'            => '',
        'file_original'       => '',
        'file_size'           => 0,
        'file_size_formatted' => '',
        'image_width'         => 0,
        'image_height'        => 0,
        'thumb'               => '',
        'thumb_width'         => 0,
        'thumb_height'        => 0,
        'stickied'            => 0,
        'moderated'           => 1
    ];
}

function insertPost(array $post): int {
    $db = dbConnect();
    $sql = "INSERT INTO " . CHESSIB_DBPOSTS . " 
        (parent, timestamp, bumped, ip, name, tripcode, email, nameblock,
         subject, message, password, file, file_hex, file_original, file_size,
         file_size_formatted, image_width, image_height, thumb, thumb_width, thumb_height,
         stickied, moderated)
         VALUES (:parent, :ts, :bumped, :ip, :name, :trip, :email, :nblock,
                :subj, :msg, :pwd, :file, :fhex, :forig, :fsize,
                :fsizef, :iw, :ih, :thumb, :tw, :th, 0, :modded)";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':parent', $post['parent'], SQLITE3_INTEGER);
    $stmt->bindValue(':ts', $post['timestamp'], SQLITE3_INTEGER);
    $stmt->bindValue(':bumped', $post['bumped'], SQLITE3_INTEGER);
    $stmt->bindValue(':ip', $post['ip'], SQLITE3_TEXT);
    $stmt->bindValue(':name', $post['name'], SQLITE3_TEXT);
    $stmt->bindValue(':trip', $post['tripcode'], SQLITE3_TEXT);
    $stmt->bindValue(':email', $post['email'], SQLITE3_TEXT);
    $stmt->bindValue(':nblock', $post['nameblock'], SQLITE3_TEXT);
    $stmt->bindValue(':subj', $post['subject'], SQLITE3_TEXT);
    $stmt->bindValue(':msg', $post['message'], SQLITE3_TEXT);
    $stmt->bindValue(':pwd', $post['password'], SQLITE3_TEXT);
    $stmt->bindValue(':file', $post['file'], SQLITE3_TEXT);
    $stmt->bindValue(':fhex', $post['file_hex'], SQLITE3_TEXT);
    $stmt->bindValue(':forig', $post['file_original'], SQLITE3_TEXT);
    $stmt->bindValue(':fsize', $post['file_size'], SQLITE3_INTEGER);
    $stmt->bindValue(':fsizef', $post['file_size_formatted'], SQLITE3_TEXT);
    $stmt->bindValue(':iw', $post['image_width'], SQLITE3_INTEGER);
    $stmt->bindValue(':ih', $post['image_height'], SQLITE3_INTEGER);
    $stmt->bindValue(':thumb', $post['thumb'], SQLITE3_TEXT);
    $stmt->bindValue(':tw', $post['thumb_width'], SQLITE3_INTEGER);
    $stmt->bindValue(':th', $post['thumb_height'], SQLITE3_INTEGER);
    $stmt->bindValue(':modded', $post['moderated'], SQLITE3_INTEGER);
    $stmt->execute();
    return $db->lastInsertRowID();
}

function postByID(int $id): ?array {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " WHERE id=:id LIMIT 1");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function threadExistsByID(int $id): bool {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM " . CHESSIB_DBPOSTS . " 
                          WHERE id=:id AND parent=0 LIMIT 1");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return ($res && $res['cnt'] > 0);
}

function bumpThreadByID(int $id): void {
    $db = dbConnect();
    $stmt = $db->prepare("UPDATE " . CHESSIB_DBPOSTS . " 
        SET bumped=:now WHERE id=:id AND parent=0");
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function postsInThreadByID(int $id): array {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " 
        WHERE id=:id OR parent=:id ORDER BY id ASC");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $all = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $all[] = $row;
    }
    return $all;
}

function countThreads(): int {
    $db = dbConnect();
    $res = $db->query("SELECT COUNT(*) AS cnt FROM " . CHESSIB_DBPOSTS . " WHERE parent=0");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return (int)$row['cnt'];
}

function allThreads(): array {
    $db = dbConnect();
    $res = $db->query("SELECT * FROM " . CHESSIB_DBPOSTS . " WHERE parent=0 
                       ORDER BY bumped DESC");
    $threads = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $threads[] = $row;
    }
    return $threads;
}

function deletePostImages(array $post): void {
    if (!empty($post['file']) && file_exists("src/" . $post['file'])) {
        @unlink("src/" . $post['file']);
    }
    if (!empty($post['thumb']) && file_exists("thumb/" . $post['thumb'])) {
        @unlink("thumb/" . $post['thumb']);
    }
}

function deletePostByID(int $id): void {
    $db = dbConnect();
    $p = postByID($id);
    if (!$p) {
        return;
    }
    if ((int)$p['parent'] === 0) {
        $all = postsInThreadByID($id);
        foreach ($all as $pp) {
            deletePostImages($pp);
            $stmt = $db->prepare("DELETE FROM " . CHESSIB_DBPOSTS . " WHERE id=:pid");
            $stmt->bindValue(':pid', (int)$pp['id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
        @unlink("res/" . $id . ".html");
    } else {
        deletePostImages($p);
        $stmt = $db->prepare("DELETE FROM " . CHESSIB_DBPOSTS . " WHERE id=:pid LIMIT 1");
        $stmt->bindValue(':pid', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function numRepliesToThreadByID(int $id): int {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM " . CHESSIB_DBPOSTS . " WHERE parent=:id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int)$res['cnt'];
}

function lastPostByIP(string $ip): ?array {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " 
                          WHERE ip=:ip ORDER BY id DESC LIMIT 1");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function trimThreads(): void {
    if (CHESSIB_MAXTHREADS > 0) {
        $count = countThreads();
        if ($count > CHESSIB_MAXTHREADS) {
            $threads = allThreads();
            for ($i = CHESSIB_MAXTHREADS; $i < $count; $i++) {
                deletePostByID((int)$threads[$i]['id']);
            }
        }
    }
}

/**
 * Page Building Functions
 */
function pageHeader(): string {
    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
  <script type="text/javascript">
    var active_page = "index", board_name = "b";
  </script>
  <link rel="stylesheet" media="screen" href="/stylesheets/style.css?v=0">
  <link rel="stylesheet" href="/stylesheets/font-awesome/css/font-awesome.min.css?v=0">
  <link rel="stylesheet" href="/static/flags/flags.css?v=0">
  <script type="text/javascript">
    var configRoot = "/";
    var inMod = false;
    var modRoot = "/" + (inMod ? "mod.php?/" : "");
  </script>
  <script type="text/javascript" src="/main.js?v=0" data-resource-version="0"></script>
  <script type="text/javascript" src="/js/jquery.min.js?v=0"></script>
  <script type="text/javascript" src="/js/inline-expanding.js?v=0"></script>
  <meta name="description" content="" />
  <meta name="twitter:card" value="summary">
  <meta name="twitter:title" content="/b/ - Random" />
  <meta name="twitter:description" content="" />
  <meta property="og:title" content="/b/ - Random" />
  <meta property="og:type" content="article" />
  <meta property="og:image" content="http://localhost/" />
  <meta property="og:description" content="" />
  <title>/b/ - Random</title>
</head>
<body class="8chan vichan is-not-moderator active-index" data-stylesheet="default">
  <header>
    <h1>/b/ - Random</h1>
    <div class="subtitle"></div>
  </header>
HTML;
}

function pageFooter(): string {
    return <<<HTML
  <script type="text/javascript">rememberStuff();</script>
  <hr />
  <script type="text/javascript">ready();</script>
</body>
</html>
HTML;
}

function buildPost(array $post, bool $respage = false): string {
    $id       = (int)$post['id'];
    $parent   = (int)$post['parent'];
    $threadid = ($parent === 0) ? $id : $parent;

    // Truncate if needed
    if (!$respage && defined('CHESSIB_TRUNCATE') && strlen($post['message']) > CHESSIB_TRUNCATE) {
        $post['message'] = substr($post['message'], 0, CHESSIB_TRUNCATE)
            . '<br><span class="truncated" style="font-style: italic; font-size: 90%;">Post truncated; open reply mode to view the full post</span>';
    }

    $file_html = '';
    if ($post['file'] !== '') {
        $file_path = $respage ? "../src/" . $post['file'] : "src/" . $post['file'];
        $thumb_path = $respage ? "../thumb/" . $post['thumb'] : "thumb/" . $post['thumb'];

        $file_html = '<div class="files"><div class="file">';
        $file_html .= '<a href="' . $file_path . '" target="_blank">'
            . '<img class="post-image" src="' . $thumb_path . '" style="width:255px;height:255px" alt="" /></a>';
        $file_html .= '</div></div>';
    }

    $subject = ($post['subject'] !== '') ? $post['subject'] : '';
    $name = ($post['name'] !== '') ? $post['name'] : 'Anonymous';

    $intro = '<p class="intro"><input type="checkbox" class="delete" name="delete_' . $id . '" id="delete_' . $id . '" />'
           . '<label for="delete_' . $id . '"><span class="subject">' . $subject . ' </span> '
           . '<span class="name">' . $name . '</span></label>&nbsp;';
    if (!$respage) {
        $intro .= '<a href="res/' . $threadid . '.html">[Reply]</a>';
    }
    $body = '<div class="body">' . $post['message'] . '</div>';

    $html = '<div class="thread" id="thread_' . $id . '" data-board="' . CHESSIB_BOARD . '">';
    $html .= $file_html;
    $html .= '<div class="post op" id="op_' . $id . '">' . $intro . $body . '</div>';
    $html .= '<br class="clear"/><hr/></div>';
    return $html;
}

function buildPage(string $htmlposts, int $parent = 0, int $pages = 0, int $thispage = 0): string {
    $pagination = '';
    if ($pages > 0) {
        $pagination .= '<div class="pages">';
        for ($i = 0; $i <= $pages; $i++) {
            $link  = ($i === 0) ? 'index.html' : $i . '.html';
            $style = ' style="margin: 0 5px;"';
            if ($i === $thispage) {
                $pagination .= '<a class="selected"' . $style . ' href="' . $link . '">' . ($i + 1) . '</a>';
            } else {
                $pagination .= '<a' . $style . ' href="' . $link . '">' . ($i + 1) . '</a>';
            }
        }
        $pagination .= '</div>';
    }

    $post_form = <<<HTML
<form name="post" onsubmit="return doPost(this);" enctype="multipart/form-data" action="post.php" method="post">
  <input type="hidden" name="board" value="b">
  <input type="hidden" name="parent" value="0">
  <table>
    <tr>
      <th>Name</th>
      <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off"></td>
    </tr>
    <tr>
      <th>Subject</th>
      <td>
        <input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off">
        <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Topic" />
      </td>
    </tr>
    <tr>
      <th>Comment</th>
      <td><textarea name="body" id="body" rows="5" cols="35"></textarea></td>
    </tr>
    <tr id="upload">
      <th>File</th>
      <td>
        <input type="file" name="file" id="upload_file">
        <script type="text/javascript">if (typeof init_file_selector !== 'undefined') init_file_selector(1);</script>
      </td>
    </tr>
  </table>
</form>
HTML;

    $post_controls = <<<HTML
<form name="postcontrols" action="post.php" method="post">
  <input type="hidden" name="board" value="b" />
  $htmlposts
</form>
HTML;

    return pageHeader() . $post_form . $pagination . $post_controls . $pagination . pageFooter();
}

function buildReplyPage(string $htmlposts, int $thread_id, string $thread_title): string {
    return <<<HTML
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
  <script type="text/javascript">
     var active_page = "thread", board_name = "b", thread_id = "{$thread_id}";
  </script>
  <link rel="stylesheet" media="screen" href="/stylesheets/style.css?v=0">
  <link rel="stylesheet" href="/stylesheets/font-awesome/css/font-awesome.min.css?v=0">
  <link rel="stylesheet" href="/static/flags/flags.css?v=0">
  <script type="text/javascript">
     var configRoot = "/";
     var inMod = false;
     var modRoot = "/" + (inMod ? "mod.php?/" : "");
  </script>
  <script type="text/javascript" src="/main.js?v=0" data-resource-version="0"></script>
  <script type="text/javascript" src="/js/jquery.min.js?v=0"></script>
  <script type="text/javascript" src="/js/inline-expanding.js?v=0"></script>
  <meta name="description" content="{$thread_title}" />
  <meta name="twitter:card" value="summary">
  <meta name="twitter:title" content="{$thread_title}" />
  <meta name="twitter:description" content="{$thread_title}" />
  <meta name="twitter:image" content="http://localhost/thumb/placeholder.png" />
  <meta property="og:title" content="{$thread_title}" />
  <meta property="og:type" content="article" />
  <meta property="og:url" content="http://localhost/res/{$thread_id}.html" />
  <meta property="og:image" content="http://localhost/thumb/placeholder.png" />
  <meta property="og:description" content="{$thread_title}" />
  <title>/b/ - {$thread_title}</title>
</head>
<body class="8chan vichan is-not-moderator active-thread" data-stylesheet="default">
  <a name="top"></a>
  <header>
     <h1>/b/ - Random</h1>
     <div class="subtitle"></div>
  </header>
  <div class="banner">Posting mode: Reply <a class="unimportant" href="../index.html">[Return]</a> <a class="unimportant" href="#bottom">[Go to bottom]</a></div>
  <form name="post" onsubmit="return doPost(this);" enctype="multipart/form-data" action="../post.php" method="post">
    <input type="hidden" name="parent" value="{$thread_id}">
    <input type="hidden" name="board" value="b">
    <table>
      <tr>
         <th>Name</th>
         <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off"></td>
      </tr>
      <tr>
         <th>Subject</th>
         <td>
           <input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off">
           <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Reply" />
         </td>
      </tr>
      <tr>
         <th>Comment</th>
         <td><textarea name="body" id="body" rows="5" cols="35"></textarea></td>
      </tr>
      <tr id="upload">
         <th>File</th>
         <td>
           <input type="file" name="file" id="upload_file">
           <script type="text/javascript">if (typeof init_file_selector !== 'undefined') init_file_selector(1);</script>
         </td>
      </tr>
    </table>
  </form>
  <script type="text/javascript">rememberStuff();</script>
  <hr />
  <form name="postcontrols" action="../post.php" method="post">
    <input type="hidden" name="board" value="b" />
    <div class="thread" id="thread_{$thread_id}" data-board="b">
      {$htmlposts}
    </div>
    <div id="thread-interactions">
      <span id="thread-links">
         <a id="thread-return" href="../index.html">[Return]</a>
         <a id="thread-top" href="#top">[Go to top]</a>
         <a id="thread-catalog" href="../catalog.html">Catalog</a>
      </span>
      <span id="thread-quick-reply"><a id="link-quick-reply" href="#">[Post a Reply]</a></span>
    </div>
    <div class="clearfix"></div>
  </form>
  <a name="bottom"></a>
  <footer>
    <p class="unimportant" style="margin-top:20px;text-align:center;">- Board powered by Adelia -</p>
  </footer>
  <script type="text/javascript">ready();</script>
</body>
</html>
HTML;
}

/**
 * Rebuild Functions
 */
function writePage(string $filename, string $contents): void {
    $tmp = tempnam("res/", "chessibtmp");
    file_put_contents($tmp, $contents);
    @rename($tmp, $filename);
    @chmod($filename, 0664);
}

function rebuildThread(int $id): void {
    $posts = postsInThreadByID($id);
    $html = "";
    foreach ($posts as $p) {
        $html .= buildPost($p, true);
    }
    $html .= "<br class=\"clear\"><hr>";
    $threadPost = postByID($id);
    $thread_title = ($threadPost && trim($threadPost['subject']) !== "") ? $threadPost['subject'] : "Thread " . $id;
    $content = buildReplyPage($html, $id, $thread_title);
    writePage("res/" . $id . ".html", $content);
}

function rebuildIndexes(): void {
    $threads = allThreads();
    $pages = max(0, ceil(count($threads) / CHESSIB_THREADSPERPAGE) - 1);
    $page = 0;
    $i = 0;
    $threadhtml = '';

    foreach ($threads as $th) {
        $threadhtml .= buildPost($th, false);
        $threadhtml .= "<br class=\"clear\"><hr>";
        $i++;
        if ($i >= CHESSIB_THREADSPERPAGE) {
            $fname = ($page === 0 ? 'index' : $page) . '.html';
            writePage($fname, buildPage($threadhtml, 0, $pages, $page));
            $page++;
            $i = 0;
            $threadhtml = '';
        }
    }
    if ($i > 0 || $page === 0) {
        $fname = ($page === 0 ? 'index' : $page) . '.html';
        writePage($fname, buildPage($threadhtml, 0, $pages, $page));
    }
}

/**
 * Include moderation functions from x1.php
 */
require_once 'x1.php';

/**
 * Helper: Create a thumbnail from an image file.
 */
function createThumbnail(string $src_path, string $thumb_path, int $new_w, int $new_h): bool {
    $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));
    $src_img = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($src_path),
        'png'         => @imagecreatefrompng($src_path),
        'gif'         => @imagecreatefromgif($src_path),
        default       => false
    };

    if (!$src_img) {
        return false;
    }
    $old_w = imagesx($src_img);
    $old_h = imagesy($src_img);
    $scale = min($new_w / $old_w, $new_h / $old_h);
    $thumb_w = max(1, (int)round($old_w * $scale));
    $thumb_h = max(1, (int)round($old_h * $scale));
    $thumb_img = imagecreatetruecolor($thumb_w, $thumb_h);

    if ($ext === 'png') {
        imagealphablending($thumb_img, false);
        imagesavealpha($thumb_img, true);
        $transparent = imagecolorallocatealpha($thumb_img, 0, 0, 0, 127);
        imagefilledrectangle($thumb_img, 0, 0, $thumb_w, $thumb_h, $transparent);
    }

    imagecopyresampled(
        $thumb_img,
        $src_img,
        0,
        0,
        0,
        0,
        $thumb_w,
        $thumb_h,
        $old_w,
        $old_h
    );

    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($thumb_img, $thumb_path, 80),
        'png'         => imagepng($thumb_img, $thumb_path),
        'gif'         => imagegif($thumb_img, $thumb_path),
        default       => null
    };

    imagedestroy($thumb_img);
    imagedestroy($src_img);
    return true;
}

/**
 * Return a list of the most recent posts, optionally only approved ones.
 */
function latestPosts(bool $onlyApproved = false, int $limit = 100): array {
    $db = dbConnect();
    $sql = "SELECT * FROM " . CHESSIB_DBPOSTS;
    if ($onlyApproved) {
        $sql .= " WHERE moderated=1";
    }
    $sql .= " ORDER BY id DESC LIMIT :lim";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':lim', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $posts = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $posts[] = $row;
    }
    return $posts;
}

// ----------------------------------------------------------------------
// Main Logic
// ----------------------------------------------------------------------
if (!defined('INSTALL_MODE')) {
    $postid = null;
    $parent = 0;

    if (CHESSIB_TRIPSEED === '' || CHESSIB_ADMINPASS === '') {
        fancyDie("CHESSIB_TRIPSEED and CHESSIB_ADMINPASS must be configured!");
    }

    $redirect = true;
    if (isset($_POST['body']) || isset($_FILES['file'])) {
        [$loggedin, $isadmin] = manageCheckLogIn();

        if (!$loggedin) {
            $lastpost = lastPostByIP($_SERVER['REMOTE_ADDR']);
            if ($lastpost) {
                $diff = time() - (int)$lastpost['timestamp'];
                if ($diff < CHESSIB_DELAY) {
                    fancyDie("Please wait " . (CHESSIB_DELAY - $diff) . " more second(s) before posting again.");
                }
            }
        }

        $rawpost = (isset($_POST['rawpost']) && $loggedin);

        if (isset($_POST['parent'])) {
            $parent = (int)$_POST['parent'];
            if ($parent !== 0 && !threadExistsByID($parent)) {
                fancyDie("Invalid parent thread ID.");
            }
        }

        $post = newPost($parent);
        $post['timestamp'] = time();
        $post['bumped']    = time();
        $post['ip']        = $_SERVER['REMOTE_ADDR'];

        $post['name']    = cleanString(substr($_POST['name'] ?? '', 0, 75));
        $post['tripcode'] = '';
        $post['subject'] = cleanString(substr($_POST['subject'] ?? '', 0, 100));

        if ($rawpost) {
            $rawposttext       = $isadmin ? ' <span style="color:red;">## Admin</span>' : ' <span style="color:purple;">## Mod</span>';
            $post['message']   = $_POST['body'];
        } else {
            $rawposttext = '';
            $msg = rtrim($_POST['body'] ?? '');
            $msg = cleanString($msg);
            $msg = str_replace("\n", "<br>", $msg);
            $post['message'] = $msg;
        }

        $post['password']  = '';
        $post['email']     = '';
        $post['nameblock'] = nameBlock($post['name'], $post['tripcode'], '', $post['timestamp'], $rawposttext);

        if (isset($_FILES['file']) && $_FILES['file']['name'] !== '') {
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                fancyDie("File upload error: {$_FILES['file']['error']}");
            }
            if (CHESSIB_MAXKB > 0 && $_FILES['file']['size'] > (CHESSIB_MAXKB * 1024)) {
                fancyDie("File too large. Max is " . CHESSIB_MAXKBDESC);
            }
            $tmp = $_FILES['file']['tmp_name'];
            if (!is_file($tmp)) {
                fancyDie("No uploaded file found.");
            }
            $post['file_original'] = substr($_FILES['file']['name'], 0, 50);
            $post['file_hex']      = md5_file($tmp);
            $post['file_size']     = filesize($tmp);
            $post['file_size_formatted'] = convertBytes($post['file_size']);

            $db = dbConnect();
            $st = $db->prepare("SELECT id FROM " . CHESSIB_DBPOSTS . " WHERE file_hex=:fh LIMIT 1");
            $st->bindValue(':fh', $post['file_hex'], SQLITE3_TEXT);
            $dupe = $st->execute()->fetchArray(SQLITE3_ASSOC);
            if ($dupe) {
                fancyDie(
                    "Duplicate file. Already posted <a href=\"res/" . $dupe['id'] . ".html\">here</a>."
                );
            }

            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }
            $fname = time() . substr((string)microtime(), 2, 3) . '.' . $ext;
            $post['file']  = $fname;
            $post['thumb'] = time() . substr((string)microtime(), 2, 3) . 's.' . $ext;
            $dst = "src/" . $fname;
            if (!move_uploaded_file($tmp, $dst)) {
                fancyDie("Could not move uploaded file.");
            }

            $info = @getimagesize($dst);
            $mime = $info['mime'] ?? '';
            $post['image_width']  = 0;
            $post['image_height'] = 0;

            if ($ext === 'webm') {
                if (!CHESSIB_WEBM) {
                    fancyDie("Unsupported file type.");
                }
                $thb = "thumb/" . $post['thumb'];
                // A simple placeholder for the webm thumbnail
                copy("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFgAAABYCAYAAABX2UokAAAA0UlEQVR42u3QwQnAIBBE0c0v9ElZJL2pukl7g0Ao1/mtzD0ybA47b4cFMLDgypgWWDPBQkzJO4BAAAAAAAAAAAA7x2RJCf4rWZb04AAAAASUVORK5CYII=", $thb);
                $post['thumb_width'] = 88;
                $post['thumb_height'] = 88;
            } else {
                if (!CHESSIB_PIC) {
                    fancyDie("Unsupported file type.");
                }
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
                    fancyDie("Unsupported image type.");
                }
                $post['image_width']  = $info[0];
                $post['image_height'] = $info[1];
                [$tw, $th] = [CHESSIB_MAXWOP, CHESSIB_MAXHOP];
                $thb = "thumb/" . $post['thumb'];
                if (!createThumbnail($dst, $thb, $tw, $th)) {
                    fancyDie("Could not create thumbnail.");
                }
                $thInfo = @getimagesize($thb);
                $post['thumb_width']  = $thInfo[0];
                $post['thumb_height'] = $thInfo[1];
            }
        } else {
            $tempMsg = strip_tags(str_replace('<br>', '', $post['message']));
            if ($tempMsg === '') {
                fancyDie("Please enter a comment.");
            }
        }

        if (
            !$loggedin &&
            (
                ($post['file'] !== '' && CHESSIB_REQMOD === 'files')
                || CHESSIB_REQMOD === 'all'
            )
        ) {
            $post['moderated'] = 0;
            echo "Your post will be shown once approved.<br>";
            $slow_redirect = true;
        }

        $postid = insertPost($post);

        if ($post['moderated'] === 1) {
            trimThreads();
            if ($parent !== 0) {
                rebuildThread($parent);
                if (strtolower($post['email']) !== 'sage') {
                    if (CHESSIB_MAXREPLIES === 0 || numRepliesToThreadByID($parent) <= CHESSIB_MAXREPLIES) {
                        bumpThreadByID($parent);
                    }
                }
            } else {
                rebuildThread($postid);
            }
            rebuildIndexes();
        }
    } elseif (isset($_GET['delete']) && !isset($_GET['manage'])) {
        if (!isset($_POST['delete'])) {
            fancyDie('Tick the box next to a post and click "Delete".');
        }
        $delid = (int)$_POST['delete'];
        $p = postByID($delid);
        if (!$p) {
            fancyDie('Invalid post ID given.');
        }

        [$loggedin, $isadmin] = manageCheckLogIn();
        if ($loggedin && ($_POST['password'] ?? '') === '') {
            echo '<meta http-equiv="refresh" content="0;url=' . basename($_SERVER['PHP_SELF']) . '?manage&moderate=' . $delid . '">';
            $redirect = false;
        } else {
            if (
                $p['password'] !== '' &&
                md5(md5($_POST['password'] ?? '')) === $p['password']
            ) {
                $par = (int)$p['parent'];
                deletePostByID($delid);
                if ($par !== 0) {
                    rebuildThread($par);
                }
                rebuildIndexes();
                fancyDie('Post deleted.');
            } else {
                fancyDie('Invalid password.');
            }
        }
    } elseif (isset($_GET['manage'])) {
        $text = '';
        $onload = '';
        [$loggedin, $isadmin] = manageCheckLogIn();
        $returnlink = basename($_SERVER['PHP_SELF']);
        $redirect = false;

        if ($loggedin) {
            if ($isadmin && isset($_GET['rebuildall'])) {
                $all = allThreads();
                foreach ($all as $t) {
                    rebuildThread((int)$t['id']);
                }
                rebuildIndexes();
                $text .= "<div>Rebuilt board.</div>";
            } elseif (isset($_GET['delete'])) {
                $did = (int)$_GET['delete'];
                $p = postByID($did);
                if ($p) {
                    deletePostByID($did);
                    rebuildIndexes();
                    if ((int)$p['parent'] !== 0) {
                        rebuildThread((int)$p['parent']);
                    }
                    $text .= '<div>Post ' . $did . ' deleted.</div>';
                } else {
                    fancyDie("No post with that ID");
                }
            } elseif (isset($_GET['approve'])) {
                $aid = (int)$_GET['approve'];
                $p = postByID($aid);
                if ($p) {
                    approvePostByID($aid);
                    $tid = ((int)$p['parent'] === 0) ? (int)$p['id'] : (int)$p['parent'];
                    if (strtolower($p['email']) !== 'sage'
                        && (CHESSIB_MAXREPLIES === 0 || numRepliesToThreadByID($tid) <= CHESSIB_MAXREPLIES)
                    ) {
                        bumpThreadByID($tid);
                    }
                    rebuildThread($tid);
                    rebuildIndexes();
                    $text .= '<div>Post ' . $aid . ' approved.</div>';
                } else {
                    fancyDie("No post with that ID");
                }
            } elseif (isset($_GET['moderate'])) {
                $mid = (int)$_GET['moderate'];
                if ($mid > 0) {
                    $p = postByID($mid);
                    if ($p) {
                        $text .= manageModeratePost($p);
                    } else {
                        fancyDie("No post with that ID");
                    }
                } else {
                    $onload = ' onload="document.tinyib.moderate.focus();"';
                    $text .= manageModeratePostForm();
                }
            } elseif (isset($_GET['rawpost'])) {
                $onload = ' onload="document.tinyib.message.focus();"';
                $text .= manageRawPostForm();
            } elseif (isset($_GET['logout'])) {
                $_SESSION['tinyib'] = '';
                session_destroy();
                echo '<meta http-equiv="refresh" content="0;url=' . $returnlink . '?manage">';
                exit;
            }
            if ($text === '') {
                $text .= manageStatus();
            }
        } else {
            $onload = ' onload="document.tinyib.password.focus();"';
            $text .= manageLogInForm();
        }
        echo managePage($text, $onload);
    } else {
        if (!file_exists('index.html') || countThreads() === 0) {
            rebuildIndexes();
        }
    }

    if ($redirect && $postid !== null) {
        $redirect_url = ($parent !== 0)
            ? "res/" . $parent . ".html?" . time() . "#{$postid}"
            : "index.html?" . time();
        echo '<meta http-equiv="refresh" content="' . (isset($slow_redirect) ? 3 : 0) . ';url=' . $redirect_url . '">';
    }
}
