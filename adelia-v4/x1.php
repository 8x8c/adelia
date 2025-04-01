<?php
declare(strict_types=1);

// x1.php: Contains all moderation-related functions

function manageCheckLogIn(): array {
    $loggedin = false;
    $isadmin  = false;
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
            $isadmin  = true;
        } elseif (CHESSIB_MODPASS !== '' && $_SESSION['tinyib'] === CHESSIB_MODPASS) {
            $loggedin = true;
        }
    }
    return [$loggedin, $isadmin];
}

function managePage(string $body, string $onload = ''): string {
    $r = basename($_SERVER['PHP_SELF']);
    [$loggedin, $isadmin] = manageCheckLogIn();
    $adminbar = '[<a href="' . $r . '">Return</a>]';
    if ($loggedin) {
        $adminbar = '[<a href="?manage">Status</a>] '
            . '[<a href="?manage&moderate">Moderate Post</a>] '
            . '[<a href="?manage&rawpost">Raw Post</a>] '
            . ($isadmin ? '[<a href="?manage&rebuildall">Rebuild All</a>] ' : '')
            . '[<a href="?manage&logout">Log Out</a>] &middot; [<a href="' . $r . '">Return</a>]';
    }
    return pageHeader()
        . '<body' . $onload . '>
    <div style="text-align:right">' . $adminbar . '</div>
    <header><h1>' . CHESSIB_BOARDDESC . '</h1></header>
    <hr>
    <div class="replymode">Manage mode</div>
    ' . $body . '
    <hr>' . pageFooter();
}

function manageLogInForm(): string {
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

function manageStatus(): string {
    $threads = countThreads();
    $info = "$threads thread(s).";
    $reqmod_post_html = '';
    if (CHESSIB_REQMOD !== 'disable') {
        $all = latestPosts(false);
        foreach ($all as $p) {
            if ($p['moderated'] == 0) {
                if ($reqmod_post_html !== '') {
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
    if ($reqmod_post_html !== '') {
        $reqmod_html = '<fieldset><legend>Pending posts</legend>' . $reqmod_post_html . '</fieldset>';
    }
    $post_html = '';
    $latest = latestPosts(true);
    $c = 0;
    foreach ($latest as $lp) {
        if ($c >= 5) {
            break;
        }
        if ($post_html !== '') {
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

function manageModeratePostForm(): string {
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

function manageRawPostForm(): string {
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

function manageModeratePost(array $post): string {
    $delete_info = ((int)$post['parent'] === 0) ? 'Delete entire thread below' : 'Delete only this post';
    $post_html = '';
    if ((int)$post['parent'] === 0) {
        $arr = postsInThreadByID((int)$post['id']);
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
</table>
</fieldset>
<fieldset>
<legend>Post/Thread</legend>
$post_html
</fieldset>
</fieldset><br>
EOF;
}

function approvePostByID(int $id): void {
    $db = dbConnect();
    $stmt = $db->prepare("UPDATE " . CHESSIB_DBPOSTS . " SET moderated=1 WHERE id=:id");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
}
