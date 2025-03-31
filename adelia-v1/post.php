<?php
// ----------------------------------------------------------------------
// Configuration
// ----------------------------------------------------------------------

// Admin/Mod Passwords
define('CHESSIB_ADMINPASS', "aaa");  // Full board access
define('CHESSIB_MODPASS',   "mmm");  // Moderator access (optional)

// Board Identity (display only)
define('CHESSIB_BOARD', "b");
define('CHESSIB_BOARDDESC', "/b/ - Random");

// Behavior / Appearance
define('CHESSIB_CAPTCHA', false);
define('CHESSIB_REQMOD', "disable");
define('CHESSIB_THREADSPERPAGE', 10);
define('CHESSIB_PREVIEWREPLIES', 3);
define('CHESSIB_TRUNCATE', 15);

// Flood / Limit
define('CHESSIB_DELAY', 30);             // Seconds between posts from same IP
define('CHESSIB_MAXTHREADS', 100);       // Max threads on the board
define('CHESSIB_MAXREPLIES', 0);         // 0 = unlimited

// File Types
define('CHESSIB_PIC', true);             // Allow JPG/PNG/GIF
define('CHESSIB_WEBM', true);            // Allow WebM

// File Control
define('CHESSIB_MAXKB', 10240);          // Max upload in KB
define('CHESSIB_MAXKBDESC', "10 MB");
define('CHESSIB_NOFILEOK', false);       // New threads must have a file

// Thumbnail dimensions
define('CHESSIB_MAXWOP', 250);           // OP thumbnail max width
define('CHESSIB_MAXHOP', 250);           // OP thumbnail max height
define('CHESSIB_MAXW',   250);           // Reply thumbnail max width
define('CHESSIB_MAXH',   250);           // Reply thumbnail max height

// Tripcode seed
define('CHESSIB_TRIPSEED', "some_random_string_for_tripcodes");

// Database (SQLite3) settings
define('CHESSIB_DBMODE', "sqlite3");
define('CHESSIB_DBNAME', "chessib.db");
define('CHESSIB_DBPOSTS', "posts");
define('CHESSIB_DBBANS',  "bans");

// Optional logo HTML
define('CHESSIB_LOGO', "");

// ----------------------------------------------------------------------
// Initialization
// ----------------------------------------------------------------------
error_reporting(E_ALL);
ini_set("display_errors", 1);
session_start();
ob_implicit_flush();

// Ensure required directories exist
$writedirs = array("res", "src", "thumb");
foreach ($writedirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir);
    }
    if (!is_writable($dir)) {
        die("Directory '{$dir}' is not writable. Fix permissions or create it.");
    }
}

/**
 * Utility Functions
 */
function fancyDie($message) {
    die('<body><p>' . $message . '</p><p><a href="javascript:history.go(-1)">Back</a></p></body>');
}

function cleanString($string) {
    return str_replace(array("<", ">"), array("&lt;", "&gt;"), $string);
}

function plural($singular, $count, $plural = 's') {
    if ($plural === 's') {
        $plural = $singular . $plural;
    }
    return ($count == 1 ? $singular : $plural);
}

function convertBytes($number) {
    $len = strlen($number);
    if ($len < 4) return sprintf("%dB", $number);
    elseif ($len <= 6) return sprintf("%0.2fKB", $number / 1024);
    elseif ($len <= 9) return sprintf("%0.2fMB", $number / 1024 / 1024);
    return sprintf("%0.2fGB", $number / 1024 / 1024 / 1024);
}

function nameAndTripcode($name) {
    if (preg_match("/(#|!)(.*)/", $name, $regs)) {
        $cap = $regs[2];
        if (function_exists('mb_convert_encoding')) {
            $recoded_cap = mb_convert_encoding($cap, 'SJIS', 'UTF-8');
            if ($recoded_cap !== '') {
                $cap = $recoded_cap;
            }
        }
        $cap_delimiter = (strpos($name, '#') !== false) ? '#' : '!';
        if (preg_match("/(.*)($cap_delimiter)(.*)/", $cap, $regs_secure)) {
            $cap = $regs_secure[1];
            $cap_secure = $regs_secure[3];
            $is_secure_trip = true;
        } else {
            $is_secure_trip = false;
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
            if ($cap !== "") $tripcode .= "!";
            $tripcode .= "!" . substr(md5($cap_secure . CHESSIB_TRIPSEED), 2, 10);
        }
        $nameonly = preg_replace("/($cap_delimiter)(.*)/", "", $name);
        return array($nameonly, $tripcode);
    }
    return array($name, "");
}

function nameBlock($name, $tripcode, $email, $timestamp, $rawposttext) {
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
function dbConnect() {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(CHESSIB_DBNAME);
        $db->busyTimeout(5000);
        $db->exec("CREATE TABLE IF NOT EXISTS " . CHESSIB_DBPOSTS . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent INTEGER NOT NULL,
            timestamp INTEGER NOT NULL,
            bumped INTEGER NOT NULL,
            ip TEXT NOT NULL,
            name TEXT NOT NULL,
            tripcode TEXT NOT NULL,
            email TEXT NOT NULL,
            nameblock TEXT NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            password TEXT NOT NULL,
            file TEXT NOT NULL,
            file_hex TEXT NOT NULL,
            file_original TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            file_size_formatted TEXT NOT NULL,
            image_width INTEGER NOT NULL,
            image_height INTEGER NOT NULL,
            thumb TEXT NOT NULL,
            thumb_width INTEGER NOT NULL,
            thumb_height INTEGER NOT NULL,
            stickied INTEGER NOT NULL DEFAULT 0,
            moderated INTEGER NOT NULL DEFAULT 1
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS " . CHESSIB_DBBANS . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            timestamp INTEGER NOT NULL,
            expire INTEGER NOT NULL,
            reason TEXT NOT NULL
        )");
    }
    return $db;
}

function newPost($parent = 0) {
    return array(
        'id' => 0,
        'parent' => $parent,
        'timestamp' => 0,
        'bumped' => 0,
        'ip' => '',
        'name' => '',
        'tripcode' => '',
        'email' => '',
        'nameblock' => '',
        'subject' => '',
        'message' => '',
        'password' => '',
        'file' => '',
        'file_hex' => '',
        'file_original' => '',
        'file_size' => 0,
        'file_size_formatted' => '',
        'image_width' => 0,
        'image_height' => 0,
        'thumb' => '',
        'thumb_width' => 0,
        'thumb_height' => 0,
        'stickied' => 0,
        'moderated' => 1
    );
}

function insertPost($post) {
    $db = dbConnect();
    $stmt = $db->prepare("INSERT INTO " . CHESSIB_DBPOSTS . " 
        (parent, timestamp, bumped, ip, name, tripcode, email, nameblock,
         subject, message, password, file, file_hex, file_original, file_size,
         file_size_formatted, image_width, image_height, thumb, thumb_width, thumb_height,
         stickied, moderated)
         VALUES (:parent, :ts, :bumped, :ip, :name, :trip, :email, :nblock,
                :subj, :msg, :pwd, :file, :fhex, :forig, :fsize,
                :fsizef, :iw, :ih, :thumb, :tw, :th, 0, :modded)");
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

function postByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " WHERE id=:id LIMIT 1");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function threadExistsByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM " . CHESSIB_DBPOSTS . " 
                          WHERE id=:id AND parent=0 LIMIT 1");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return ($res && $res['cnt'] > 0);
}

function bumpThreadByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("UPDATE " . CHESSIB_DBPOSTS . " 
        SET bumped=:now WHERE id=:id AND parent=0");
    $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $stmt->execute();
}

function postsInThreadByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " 
        WHERE id=:id OR parent=:id ORDER BY id ASC");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $all = array();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $all[] = $row;
    }
    return $all;
}

function countThreads() {
    $db = dbConnect();
    $res = $db->query("SELECT COUNT(*) AS cnt FROM " . CHESSIB_DBPOSTS . " WHERE parent=0");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return (int)$row['cnt'];
}

function allThreads() {
    $db = dbConnect();
    $res = $db->query("SELECT * FROM " . CHESSIB_DBPOSTS . " WHERE parent=0 
                       ORDER BY bumped DESC");
    $threads = array();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $threads[] = $row;
    }
    return $threads;
}

function deletePostImages($post) {
    if (!empty($post['file']) && file_exists("src/" . $post['file'])) {
        @unlink("src/" . $post['file']);
    }
    if (!empty($post['thumb']) && file_exists("thumb/" . $post['thumb'])) {
        @unlink("thumb/" . $post['thumb']);
    }
}

function deletePostByID($id) {
    $db = dbConnect();
    $p = postByID($id);
    if (!$p) return;
    if ($p['parent'] == 0) {
        // OP: delete entire thread
        $all = postsInThreadByID($id);
        foreach ($all as $pp) {
            deletePostImages($pp);
            $stmt = $db->prepare("DELETE FROM " . CHESSIB_DBPOSTS . " WHERE id=:pid");
            $stmt->bindValue(':pid', (int)$pp['id'], SQLITE3_INTEGER);
            $stmt->execute();
        }
        @unlink("res/" . $id . ".html");
    } else {
        // Single reply
        deletePostImages($p);
        $stmt = $db->prepare("DELETE FROM " . CHESSIB_DBPOSTS . " WHERE id=:pid LIMIT 1");
        $stmt->bindValue(':pid', (int)$id, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

function numRepliesToThreadByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM " . CHESSIB_DBPOSTS . " WHERE parent=:id");
    $stmt->bindValue(':id', (int)$id, SQLITE3_INTEGER);
    $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int)$res['cnt'];
}

function lastPostByIP($ip) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBPOSTS . " 
                          WHERE ip=:ip ORDER BY id DESC LIMIT 1");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

function trimThreads() {
    if (CHESSIB_MAXTHREADS > 0) {
        $count = countThreads();
        if ($count > CHESSIB_MAXTHREADS) {
            $threads = allThreads();
            for ($i = CHESSIB_MAXTHREADS; $i < $count; $i++) {
                deletePostByID($threads[$i]['id']);
            }
        }
    }
}

/**
 * Ban Functions
 */
function banByIP($ip) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBBANS . " WHERE ip=:ip LIMIT 1");
    $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

function insertBan($ban) {
    $db = dbConnect();
    $stmt = $db->prepare("INSERT INTO " . CHESSIB_DBBANS . " 
        (ip, timestamp, expire, reason)
        VALUES (:ip, :ts, :ex, :reason)");
    $stmt->bindValue(':ip', $ban['ip'], SQLITE3_TEXT);
    $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':ex', $ban['expire'], SQLITE3_INTEGER);
    $stmt->bindValue(':reason', $ban['reason'], SQLITE3_TEXT);
    $stmt->execute();
}

function clearExpiredBans() {
    $db = dbConnect();
    $now = time();
    $db->exec("DELETE FROM " . CHESSIB_DBBANS . " WHERE expire>0 AND expire<=$now");
}

function banByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("SELECT * FROM " . CHESSIB_DBBANS . " WHERE id=:id LIMIT 1");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

function deleteBanByID($id) {
    $db = dbConnect();
    $stmt = $db->prepare("DELETE FROM " . CHESSIB_DBBANS . " WHERE id=:id LIMIT 1");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}

function allBans() {
    $db = dbConnect();
    $res = $db->query("SELECT * FROM " . CHESSIB_DBBANS . " ORDER BY timestamp DESC");
    $bans = array();
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $bans[] = $r;
    }
    return $bans;
}

/**
 * Page Building Functions
 */

// Standard index page header (assets loaded from root)
function pageHeader() {
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
  <meta name="twitter:image" content="http://localhost/" />
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

function pageFooter() {
    return <<<HTML
  <script type="text/javascript">rememberStuff();</script>
  <hr />
  <script type="text/javascript">ready();</script>
</body>
</html>
HTML;
}

// buildPost() outputs an individual post.
// When $respage is true (i.e. when the post is displayed on a reply page),
// file paths for images are prefixed with "../".
function buildPost($post, $respage = false) {
    $id = $post['id'];
    $parent = $post['parent'];
    $threadid = ($parent == 0) ? $id : $parent;
    $time_iso = date('Y-m-d\TH:i:s', $post['timestamp']);
    $time_formatted = date('m/d/y (D) H:i:s', $post['timestamp']);
    $file_html = '';
    if ($post['file'] != '') {
        if ($respage) {
            $file_path = "../src/" . $post['file'];
            $thumb_path = "../thumb/" . $post['thumb'];
        } else {
            $file_path = "src/" . $post['file'];
            $thumb_path = "thumb/" . $post['thumb'];
        }
        $file_html = '<div class="files"><div class="file">';
        $file_html .= '<p class="fileinfo">File: <a href="' . $file_path . '">' . $post['file'] . '</a> <span class="unimportant">('
            . $post['file_size_formatted'] . ', ' . $post['image_width'] . 'x' . $post['image_height'] . ', <a class="postfilename" href="'
            . $file_path . '" download="' . $post['file_original'] . '" title="Save as original filename">' . $post['file_original'] . '</a>)</span></p>';
        $file_html .= '<a href="' . $file_path . '" target="_blank"><img class="post-image" src="' . $thumb_path . '" style="width:255px;height:255px" alt="" /></a>';
        $file_html .= '</div></div>';
    }
    $subject = ($post['subject'] != '') ? $post['subject'] : '';
    $name = ($post['name'] != '') ? $post['name'] : 'Anonymous';
    $intro = '<p class="intro"><input type="checkbox" class="delete" name="delete_' . $id . '" id="delete_' . $id . '" /><label for="delete_' . $id . '"><span class="subject">' . $subject . ' </span> <span class="name">' . $name . '</span> <time datetime="' . $time_iso . '">' . $time_formatted . '</time></label>&nbsp;';
    $intro .= '<a class="post_no" id="post_no_' . $id . '" onclick="highlightReply(' . $id . ')" href="res/' . $threadid . '.html#' . $id . '">No.</a>';
    $intro .= '<a class="post_no" onclick="citeReply(' . $id . ')" href="res/' . $threadid . '.html#q' . $id . '">' . $id . '</a>';
    $intro .= '<a href="res/' . $threadid . '.html">[Reply]</a></p>';
    $body = '<div class="body">' . $post['message'] . '</div>';
    $html = '<div class="thread" id="thread_' . $id . '" data-board="' . CHESSIB_BOARD . '">';
    $html .= $file_html;
    $html .= '<div class="post op" id="op_' . $id . '">' . $intro . $body . '</div>';
    $html .= '<br class="clear"/><hr/></div>';
    return $html;
}

// buildPage() creates an index page with a posting form and a list of threads.
function buildPage($htmlposts, $parent = 0, $pages = 0, $thispage = 0) {
    $post_form = <<<HTML
<form name="post" onsubmit="return doPost(this);" enctype="multipart/form-data" action="post.php" method="post">
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
    return pageHeader() . $post_form . $post_controls . pageFooter();
}

// buildReplyPage() creates a reply (thread) page.
// Note: All form actions and links are adjusted with "../" because this page is in the "res" folder.
function buildReplyPage($htmlposts, $thread_id, $thread_title) {
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
    <input type="hidden" name="thread" value="{$thread_id}">
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
function writePage($filename, $contents) {
    $tmp = tempnam("res/", "chessibtmp");
    file_put_contents($tmp, $contents);
    @rename($tmp, $filename);
    @chmod($filename, 0664);
}

function rebuildThread($id) {
    $posts = postsInThreadByID($id);
    $html = "";
    foreach ($posts as $p) {
        // On thread pages, pass true so that buildPost() adds "../" where needed.
        $html .= buildPost($p, true);
    }
    $html .= "<br class=\"clear\"><hr>";
    $threadPost = postByID($id);
    $thread_title = ($threadPost && trim($threadPost['subject']) !== "") ? $threadPost['subject'] : "Thread " . $id;
    $content = buildReplyPage($html, $id, $thread_title);
    writePage("res/" . $id . ".html", $content);
}

function rebuildIndexes() {
    $threads = allThreads();
    $pages = ceil(count($threads) / CHESSIB_THREADSPERPAGE) - 1;
    $page = 0;
    $i = 0;
    $threadhtml = '';
    foreach ($threads as $th) {
        $tid = $th['id'];
        $replies = postsInThreadByID($tid);
        $omitted = max(0, count($replies) - CHESSIB_PREVIEWREPLIES - 1);
        $preview = array();
        for ($x = count($replies) - 1; $x > $omitted; $x--) {
            if (!isset($replies[$x])) break;
            $preview[] = buildPost($replies[$x], false);
        }
        $th['omitted'] = $omitted;
        $threadhtml .= buildPost($th, false);
        $threadhtml .= implode('', array_reverse($preview));
        if ($omitted > 0) {
            $threadhtml .= '<span class="omittedposts">' . $omitted . ' ' . plural('post', $omitted) . ' omitted. Click Reply to view.</span>';
        }
        $threadhtml .= "<br class=\"clear\"><hr>";
        $i++;
        if ($i >= CHESSIB_THREADSPERPAGE) {
            $fname = ($page == 0 ? 'index' : $page) . '.html';
            writePage($fname, buildPage($threadhtml, 0, $pages, $page));
            $page++;
            $i = 0;
            $threadhtml = '';
        }
    }
    if ($i > 0 || $page == 0) {
        $fname = ($page == 0 ? 'index' : $page) . '.html';
        writePage($fname, buildPage($threadhtml, 0, $pages, $page));
    }
}

/**
 * Management Functions (unchanged)
 */
function manageCheckLogIn() {
    $loggedin = false;
    $isadmin = false;
    if (isset($_POST['password'])) {
        if ($_POST['password'] === CHESSIB_ADMINPASS) {
            $_SESSION['tinyib'] = CHESSIB_ADMINPASS;
        } elseif (CHESSIB_MODPASS !== '' && $_POST['password'] === CHESSIB_MODPASS) {
            $_SESSION['tinyib'] = CHESSIB_MODPASS;
        }
    }
    if (isset($_SESSION['tinyib'])) {
        if ($_SESSION['tinyib'] === CHESSIB_ADMINPASS) {
            $loggedin = true;
            $isadmin = true;
        } elseif (CHESSIB_MODPASS !== '' && $_SESSION['tinyib'] === CHESSIB_MODPASS) {
            $loggedin = true;
        }
    }
    return array($loggedin, $isadmin);
}

function managePage($body, $onload = '') {
    $r = basename($_SERVER['PHP_SELF']);
    list($loggedin, $isadmin) = manageCheckLogIn();
    $adminbar = '[<a href="' . $r . '">Return</a>]';
    if ($loggedin) {
        $adminbar = '[<a href="?manage">Status</a>] [' . ($isadmin ? '<a href="?manage&bans">Bans</a>] [' : '')
            . '<a href="?manage&moderate">Moderate Post</a>] [<a href="?manage&rawpost">Raw Post</a>] [' .
            ($isadmin ? '<a href="?manage&rebuildall">Rebuild All</a>] [' : '')
            . '<a href="?manage&logout">Log Out</a>] &middot; [<a href="' . $r . '">Return</a>]';
    }
    return pageHeader() . '<body' . $onload . '>
    <div style="text-align:right">' . $adminbar . '</div>
    <header><h1>' . CHESSIB_BOARDDESC . '</h1></header>
    <hr>
    <div class="replymode">Manage mode</div>
    ' . $body . '
    <hr>' . pageFooter();
}

function manageLogInForm() {
    return <<<EOF
<form id="tinyib" name="tinyib" method="post" action="?manage">
<fieldset>
<legend align="center">Enter an administrator or moderator password</legend>
<div style="text-align:center;">
<input type="password" id="password" name="password"><br>
<input type="submit" value="Log In" style="font-size:15px; height:28px; margin:0.2em;">
</div>
</fieldset>
</form><br>
EOF;
}

function manageStatus() {
    $threads = countThreads();
    $bans = count(allBans());
    $info = "$threads thread(s), $bans ban(s).";
    $reqmod_post_html = '';
    if (CHESSIB_REQMOD != 'disable') {
        $all = latestPosts(false);
        foreach ($all as $p) {
            if ($p['moderated'] == 0) {
                if ($reqmod_post_html != '') {
                    $reqmod_post_html .= '<hr>';
                }
                $reqmod_post_html .= buildPost($p, false) . '<br>';
                $reqmod_post_html .= '
                <form method="get" action="?"><input type="hidden" name="manage"><input type="hidden" name="approve" value="' . $p['id'] . '">
                <input type="submit" value="Approve"></form>
                <form method="get" action="?"><input type="hidden" name="manage"><input type="hidden" name="delete" value="' . $p['id'] . '">
                <input type="submit" value="Delete"></form>
                <form method="get" action="?"><input type="hidden" name="manage"><input type="hidden" name="moderate" value="' . $p['id'] . '">
                <input type="submit" value="Moderate"></form>
                ';
            }
        }
    }
    $reqmod_html = '';
    if ($reqmod_post_html != '') {
        $reqmod_html = '<fieldset><legend>Pending posts</legend>' . $reqmod_post_html . '</fieldset>';
    }
    $post_html = '';
    $latest = latestPosts(true);
    $c = 0;
    foreach ($latest as $lp) {
        if ($c >= 5) break;
        if ($post_html != '') {
            $post_html .= '<hr>';
        }
        $post_html .= buildPost($lp, false) . '<br>';
        $post_html .= '<form method="get" action="?"><input type="hidden" name="manage"><input type="hidden" name="moderate" value="' . $lp['id'] . '">
                     <input type="submit" value="Moderate"></form>';
        $c++;
    }
    $html = <<<EOF
<fieldset><legend>Status</legend>
<fieldset><legend>Info</legend>
<p>$info</p>
</fieldset>
$reqmod_html
<fieldset><legend>Recent Posts (approved)</legend>
$post_html
</fieldset>
</fieldset><br>
EOF;
    return $html;
}

function manageBanForm() {
    $val = isset($_GET['bans']) ? $_GET['bans'] : '';
    return <<<EOF
<form id="tinyib" name="tinyib" method="post" action="?manage&bans">
<fieldset>
<legend>Ban an IP address</legend>
<label>IP Address:</label>
<input type="text" name="ip" value="$val"> 
<input type="submit" value="Ban"><br>
<label>Expire (sec):</label>
<input type="text" name="expire" value="0">
<small>
<a href="#" onclick="document.tinyib.expire.value='3600';return false;">1hr</a>
<a href="#" onclick="document.tinyib.expire.value='86400';return false;">1d</a>
<a href="#" onclick="document.tinyib.expire.value='604800';return false;">1w</a>
<a href="#" onclick="document.tinyib.expire.value='0';return false;">never</a>
</small><br>
<label>Reason:</label>
<input type="text" name="reason">
<small>optional</small>
</fieldset>
</form><br>
EOF;
}

function manageBansTable() {
    $bans = allBans();
    if (count($bans) == 0) return "<p>No bans</p>";
    $html = '<table border="1"><tr><th>IP</th><th>Set At</th><th>Expires</th><th>Reason</th><th>&nbsp;</th></tr>';
    foreach ($bans as $b) {
        $ex = ($b['expire'] > 0) ? date('m/d/y (D) H:i:s', $b['expire']) : 'Never';
        $reason = $b['reason'] == '' ? '&nbsp;' : htmlentities($b['reason']);
        $html .= '<tr><td>' . $b['ip'] . '</td><td>' . date('m/d/y (D) H:i:s', $b['timestamp'])
            . '</td><td>' . $ex . '</td><td>' . $reason . '</td>'
            . '<td><a href="?manage&bans&lift=' . $b['id'] . '">Lift</a></td></tr>';
    }
    $html .= '</table>';
    return $html;
}

function manageModeratePostForm() {
    return <<<EOF
<form method="get" action="?">
<input type="hidden" name="manage">
<fieldset>
<legend>Moderate a post</legend>
<label>Post ID:</label> <input type="text" name="moderate"> 
<input type="submit" value="Go">
<small>
While browsing the board, tick the box near a post & click "Delete" with a blank password to moderate quickly if you are logged in.
</small>
</fieldset>
</form><br>
EOF;
}

function manageRawPostForm() {
    $max_size_html = '';
    if (CHESSIB_MAXKB > 0) {
        $max_size_html = '<input type="hidden" name="MAX_FILE_SIZE" value="' . (CHESSIB_MAXKB * 1024) . '">';
    }
    return <<<EOF
<div style="text-align:center;">
<form method="post" action="?" enctype="multipart/form-data">
<input type="hidden" name="rawpost" value="1">
$max_size_html
<table style="margin:0 auto;">
<tr><td>Reply to</td><td><input type="text" name="parent" value="0"> (0 = new thread)</td></tr>
<tr><td>Name</td><td><input type="text" name="name" maxlength="75"></td></tr>
<tr><td>E-mail</td><td><input type="text" name="email" maxlength="75"></td></tr>
<tr><td>Subject</td><td><input type="text" name="subject" maxlength="75">
<input type="submit" value="Submit"></td></tr>
<tr><td>Message (raw HTML)</td><td><textarea name="message" cols="48" rows="4"></textarea></td></tr>
<tr><td>File</td><td><input type="file" name="file"></td></tr>
<tr><td>Password</td><td><input type="password" name="password" size="8"></td></tr>
</table>
</form>
</div>
EOF;
}

function manageModeratePost($post) {
    global $isadmin;
    $ban = banByIP($post['ip']);
    $ban_disabled = (!$ban && $isadmin) ? '' : ' disabled';
    $ban_info = (!$ban) ? ((!$isadmin) ? 'Only an admin can ban.' : 'IP: ' . $post['ip']) : 'Ban already exists for ' . $post['ip'];
    $delete_info = ($post['parent'] == 0) ? 'Delete entire thread below' : 'Delete only this post';
    $post_html = '';
    if ($post['parent'] == 0) {
        $arr = postsInThreadByID($post['id']);
        foreach ($arr as $pp) {
            $post_html .= buildPost($pp, false);
        }
    } else {
        $post_html .= buildPost($post, false);
    }
    return <<<EOF
<fieldset>
<legend>Moderating No.{$post['id']}</legend>
<fieldset>
<legend>Action</legend>
<table border="0" width="100%">
<tr>
<td align="right">
<form method="get" action="?">
<input type="hidden" name="manage"><input type="hidden" name="delete" value="{$post['id']}">
<input type="submit" value="Delete" style="width:50%;">
</form>
</td>
<td>$delete_info</td>
</tr>
<tr>
<td align="right">
<form method="get" action="?">
<input type="hidden" name="manage">
<input type="hidden" name="bans" value="{$post['ip']}">
<input type="submit" value="Ban Poster" style="width:50%;"$ban_disabled>
</form>
</td>
<td>$ban_info</td>
</tr>
</table>
</fieldset>
<fieldset>
<legend>Post/Thread</legend>
$post_html
</fieldset>
</fieldset><br>
EOF;
}

/**
 * Main Logic
 */
if (CHESSIB_TRIPSEED == '' || CHESSIB_ADMINPASS == '') {
    fancyDie("CHESSIB_TRIPSEED and CHESSIB_ADMINPASS must be configured!");
}

$redirect = true;

// 1) New Post Submission
if (isset($_POST['body']) || isset($_FILES['file'])) {
    list($loggedin, $isadmin) = manageCheckLogIn();
    if (!$loggedin) {
        $ban = banByIP($_SERVER['REMOTE_ADDR']);
        if ($ban) {
            if ($ban['expire'] == 0 || $ban['expire'] > time()) {
                fancyDie("Your IP is banned.");
            } else {
                clearExpiredBans();
            }
        }
        $lastpost = lastPostByIP($_SERVER['REMOTE_ADDR']);
        if ($lastpost) {
            $diff = time() - $lastpost['timestamp'];
            if ($diff < CHESSIB_DELAY) {
                fancyDie("Please wait " . (CHESSIB_DELAY - $diff) . " more second(s) before posting again.");
            }
        }
    }
    $rawpost = (isset($_POST['rawpost']) && $loggedin);

    $parent = 0;
    if (isset($_POST['parent'])) {
        $parent = (int)$_POST['parent'];
        if ($parent != 0 && !threadExistsByID($parent)) {
            fancyDie("Invalid parent thread ID.");
        }
    }

    $post = newPost($parent);
    $post['timestamp'] = time();
    $post['bumped'] = time();
    $post['ip'] = $_SERVER['REMOTE_ADDR'];

    // Use form fields "name", "subject" and "body"
    $post['name'] = cleanString(substr($_POST['name'] ?? '', 0, 75));
    $post['tripcode'] = ''; // Not processing tripcodes here
    $post['subject'] = cleanString(substr($_POST['subject'] ?? '', 0, 100));

    if ($rawpost) {
        $rawposttext = ($isadmin) ? ' <span style="color:red;">## Admin</span>' : ' <span style="color:purple;">## Mod</span>';
        $post['message'] = $_POST['body']; // raw HTML
    } else {
        $rawposttext = '';
        $msg = rtrim($_POST['body'] ?? '');
        $msg = cleanString($msg);
        $msg = str_replace("\n", "<br>", $msg);
        $post['message'] = $msg;
    }
    $post['password'] = ''; // No password field used here
    $post['email'] = ''; // Not used
    $post['nameblock'] = nameBlock($post['name'], $post['tripcode'], '', $post['timestamp'], $rawposttext);

    // File upload handling
    if (isset($_FILES['file']) && $_FILES['file']['name'] != '') {
        if ($_FILES['file']['error'] != UPLOAD_ERR_OK) {
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
        $post['file_hex'] = md5_file($tmp);
        $post['file_size'] = filesize($tmp);
        $post['file_size_formatted'] = convertBytes($post['file_size']);

        // Check for duplicate file
        $db = dbConnect();
        $st = $db->prepare("SELECT id FROM " . CHESSIB_DBPOSTS . " WHERE file_hex=:fh LIMIT 1");
        $st->bindValue(':fh', $post['file_hex'], SQLITE3_TEXT);
        $dupe = $st->execute()->fetchArray(SQLITE3_ASSOC);
        if ($dupe) {
            fancyDie("Duplicate file. Already posted <a href=\"res/" . $dupe['id'] . ".html\">here</a>.");
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($ext == 'jpeg') $ext = 'jpg';
        $fname = time() . substr(microtime(), 2, 3) . '.' . $ext;
        $post['file'] = $fname;
        $post['thumb'] = time() . substr(microtime(), 2, 3) . 's.' . $ext;
        $dst = "src/" . $fname;
        if (!move_uploaded_file($tmp, $dst)) {
            fancyDie("Could not move uploaded file.");
        }
        $info = @getimagesize($dst);
        $mime = ($info['mime'] ?? '');
        $post['image_width'] = 0;
        $post['image_height'] = 0;
        if ($ext == 'webm') {
            if (!CHESSIB_WEBM) {
                fancyDie("Unsupported file type.");
            }
            $thb = "thumb/" . $post['thumb'];
            copy("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAFgAAABYCAYAAABX2UokAAAA0UlEQVR42u3QwQnAIBBE0c0v9ElZJL2pukl7g0Ao1/mtzD0ybA47b4cFMLDgypgWWDPBQkzJO4BAAAAAAAAAAAA7x2RJCf4rWZb04AAAAASUVORK5CYII=", $thb);
            $post['thumb_width'] = 88;
            $post['thumb_height'] = 88;
        } else {
            if (!CHESSIB_PIC) {
                fancyDie("Unsupported file type.");
            }
            if ($mime != 'image/jpeg' && $mime != 'image/png' && $mime != 'image/gif') {
                fancyDie("Unsupported image type.");
            }
            $post['image_width'] = $info[0];
            $post['image_height'] = $info[1];
            list($tw, $th) = array(CHESSIB_MAXWOP, CHESSIB_MAXHOP);
            $thb = "thumb/" . $post['thumb'];
            if (!createThumbnail($dst, $thb, $tw, $th)) {
                fancyDie("Could not create thumbnail.");
            }
            $thInfo = @getimagesize($thb);
            $post['thumb_width'] = $thInfo[0];
            $post['thumb_height'] = $thInfo[1];
        }
    } else {
        if ($parent == 0 && (CHESSIB_PIC || CHESSIB_WEBM) && !CHESSIB_NOFILEOK) {
            fancyDie("A file is required to start a thread.");
        }
        if (strip_tags(str_replace('<br>', '', $post['message'])) == '') {
            fancyDie("Please enter a comment or upload a file.");
        }
    }

    // Moderation check
    if (!$loggedin && (($post['file'] != '' && CHESSIB_REQMOD == 'files') || CHESSIB_REQMOD == 'all')) {
        $post['moderated'] = 0;
        echo "Your post will be shown once approved.<br>";
        $slow_redirect = true;
    }

    $postid = insertPost($post);
    if ($post['moderated'] == 1) {
        trimThreads();
        if ($parent != 0) {
            rebuildThread($parent);
            if (strtolower($post['email']) != 'sage') {
                if (CHESSIB_MAXREPLIES == 0 || numRepliesToThreadByID($parent) <= CHESSIB_MAXREPLIES) {
                    bumpThreadByID($parent);
                }
            }
        } else {
            rebuildThread($postid);
        }
        rebuildIndexes();
    }

// 2) Delete Post
} elseif (isset($_GET['delete']) && !isset($_GET['manage'])) {
    if (!isset($_POST['delete'])) {
        fancyDie('Tick the box next to a post and click "Delete".');
    }
    $delid = (int)$_POST['delete'];
    $p = postByID($delid);
    if (!$p) fancyDie('Invalid post ID given.');
    list($loggedin, $isadmin) = manageCheckLogIn();
    if ($loggedin && ($_POST['password'] == '')) {
        echo '<meta http-equiv="refresh" content="0;url=' . basename($_SERVER['PHP_SELF']) . '?manage&moderate=' . $delid . '">';
        $redirect = false;
    } else {
        if ($p['password'] != '' && md5(md5($_POST['password'])) == $p['password']) {
            $par = $p['parent'];
            deletePostByID($delid);
            if ($par == 0) {
                // Entire thread deleted
            } else {
                rebuildThread($par);
            }
            rebuildIndexes();
            fancyDie('Post deleted.');
        } else {
            fancyDie('Invalid password.');
        }
    }

// 3) Management (unchanged)
} elseif (isset($_GET['manage'])) {
    $text = '';
    $onload = '';
    list($loggedin, $isadmin) = manageCheckLogIn();
    $returnlink = basename($_SERVER['PHP_SELF']);
    $redirect = false;
    if ($loggedin) {
        if ($isadmin && isset($_GET['rebuildall'])) {
            $all = allThreads();
            foreach ($all as $t) {
                rebuildThread($t['id']);
            }
            rebuildIndexes();
            $text .= "<div>Rebuilt board.</div>";
        } elseif ($isadmin && isset($_GET['bans'])) {
            clearExpiredBans();
            if (isset($_POST['ip'])) {
                $ip = trim($_POST['ip']);
                if ($ip != '') {
                    $check = banByIP($ip);
                    if ($check) {
                        fancyDie("Ban already exists for $ip.");
                    }
                    $ban = array('ip' => $ip, 'expire' => 0, 'reason' => '');
                    $ban['expire'] = (int)($_POST['expire'] ?? 0);
                    if ($ban['expire'] > 0) $ban['expire'] += time();
                    $ban['reason'] = $_POST['reason'] ?? '';
                    insertBan($ban);
                    $text .= '<div>Ban record added for ' . $ip . '</div>';
                }
            } elseif (isset($_GET['lift'])) {
                $lift = (int)$_GET['lift'];
                $b = banByID($lift);
                if ($b) {
                    deleteBanByID($lift);
                    $text .= '<div>Ban lifted for ' . $b['ip'] . '</div>';
                }
            }
            $onload = ' onload="document.tinyib.ip.focus();"';
            $text .= manageBanForm();
            $text .= manageBansTable();
        } elseif (isset($_GET['delete'])) {
            $did = (int)$_GET['delete'];
            $p = postByID($did);
            if ($p) {
                deletePostByID($did);
                rebuildIndexes();
                if ($p['parent'] != 0) rebuildThread($p['parent']);
                $text .= '<div>Post ' . $did . ' deleted.</div>';
            } else {
                fancyDie("No post with that ID");
            }
        } elseif (isset($_GET['approve'])) {
            $aid = (int)$_GET['approve'];
            $p = postByID($aid);
            if ($p) {
                approvePostByID($aid);
                $tid = ($p['parent'] == 0) ? $p['id'] : $p['parent'];
                if (strtolower($p['email']) != 'sage'
                    && (CHESSIB_MAXREPLIES == 0 || numRepliesToThreadByID($tid) <= CHESSIB_MAXREPLIES)) {
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
        if ($text == '') {
            $text .= manageStatus();
        }
    } else {
        $onload = ' onload="document.tinyib.password.focus();"';
        $text .= manageLogInForm();
    }
    echo managePage($text, $onload);

// 4) Default: Rebuild index pages if needed
} else {
    if (!file_exists('index.html') || countThreads() == 0) {
        rebuildIndexes();
    }
}

if ($redirect) {
    echo '<meta http-equiv="refresh" content="' . (isset($slow_redirect) ? 3 : 0) . ';url=' . (is_string($redirect) ? $redirect : 'index.html') . '">';
}

/**
 * Helper: Create a thumbnail from an image file.
 */
function createThumbnail($src_path, $thumb_path, $new_w, $new_h) {
    $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));
    if (in_array($ext, array('jpg', 'jpeg'))) {
        $src_img = @imagecreatefromjpeg($src_path);
    } elseif ($ext == 'png') {
        $src_img = @imagecreatefrompng($src_path);
    } elseif ($ext == 'gif') {
        $src_img = @imagecreatefromgif($src_path);
    } else {
        return false;
    }
    if (!$src_img) {
        return false;
    }
    $old_w = imagesx($src_img);
    $old_h = imagesy($src_img);
    $scale = min($new_w / $old_w, $new_h / $old_h);
    $thumb_w = max(1, round($old_w * $scale));
    $thumb_h = max(1, round($old_h * $scale));
    $thumb_img = imagecreatetruecolor($thumb_w, $thumb_h);
    if ($ext == 'png') {
        imagealphablending($thumb_img, false);
        imagesavealpha($thumb_img, true);
        $transparent = imagecolorallocatealpha($thumb_img, 0, 0, 0, 127);
        imagefilledrectangle($thumb_img, 0, 0, $thumb_w, $thumb_h, $transparent);
    }
    imagecopyresampled($thumb_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_w, $old_h);
    if ($ext == 'jpg' || $ext == 'jpeg') {
        imagejpeg($thumb_img, $thumb_path, 80);
    } elseif ($ext == 'png') {
        imagepng($thumb_img, $thumb_path);
    } elseif ($ext == 'gif') {
        imagegif($thumb_img, $thumb_path);
    }
    imagedestroy($thumb_img);
    imagedestroy($src_img);
    return true;
}
?>
