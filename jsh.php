<?php
/*
 * Terminal Like php shell
 * Copyright (c) 2017 Jakub Jankiewicz <http://jcubic.pl/me>
 * Released under the MIT license
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// delete if don't want password protection
$config = array(
  'password' => 'admin',
  'root' => getcwd(),
  'storage' => true
);
 
class App {
  function __construct($root, $path) {
    $this->root = $root;
    $this->path = $path;
  }
  private function shell_exec($code) {
    return shell_exec($code);
  }
  // ------------------------------------------------------------------------
  private function exec($code) {
    exec($code, $result);
    return implode("\n", $result);
  }
  // ------------------------------------------------------------------------
  public function system($code) {
    ob_start();
    system($code);
    $result = ob_get_contents();
    ob_end_clean();
    return $result;
  }
  // ------------------------------------------------------------------------
  private function unbuffer($command, $shell_fn) {
    if (preg_match("/which unbuffer/", $command)) {
      return $command;
    } else {
      $path = preg_replace("/\s$/", "", $this->$shell_fn("which unbuffer"));
      if (empty($path)) {
        return $command;
      } else {
        return $path . " " . $command;
      }
    }
  }
  public function command($command, $path, $shell_fn) {
    if (!method_exists($this, $shell_fn)) {
        throw new Exception("Invalid shell '$shell_fn'");
    }
    $marker = 'XXXX' . md5(time());
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      $pre = "@echo off\ncd $path\n";
      $post = "\necho '$marker'%cd%";
      $command = $pre . $command . $post;
      $file = fopen("tmp.bat", "w");
      fwrite($file, $command);
      fclose($file);
      $result = $this->$shell_fn("tmp.bat");
      $result = sapi_windows_cp_conv(sapi_windows_cp_get('oem'), 65001, $result);
      $output = preg_split("/\n?'".$marker."'/", $result);
      if (count($output) == 2) {
          $cwd = preg_replace("/\n$/", '', $output[1]);
      } else {
          $cwd = $path;
      }
      return array(
          'output' => $output[0],
          'cwd' => $cwd
      );
    } else {
      $command = preg_replace("/&\s*$/", ' >/dev/null & echo $!', $command);
      $home = $this->root;
      $cmd_path = __DIR__;
      $pre = "export HOME=\"$home\"\ncd $path;\n";
      $post = ";echo -n \"$marker\";pwd";
      $command = escapeshellarg($pre . $command . $post);
      $command = $this->unbuffer($command, $shell_fn);
    }
    $result = $this->$shell_fn($command);
    if (preg_match("%>/dev/null & echo $!$%", $command)) {
        return array(
            'output' => '[1] ' . $result,
            'cwd' => $path
        );
    } else if ($result) {
        // work wth `set` that return BASH_EXECUTION_STRING
        $output = preg_split('/'.$marker.'(?!")/', $result);
        if (count($output) == 2) {
            $cwd = preg_replace("/\n$/", '', $output[1]);
        } else {
            $cwd = $path;
        }
        return array(
            'output' => preg_replace("/" . preg_quote($post) . "/", "", $output[0]),
            'cwd' => $cwd
        );
    } else {
        throw new Exception("Internal error, shell function give no result");
    }
  }
  public function shell($code) {
    $shells = array('system', 'exec', 'shell_exec');
    foreach ($shells as $shell) {
      if (function_exists($shell)) {
        return $this->command($code, $this->path, $shell);
      }
    }
    throw new Exception("No valid shell found");
  }
}

function token() {
  $time = array_sum(explode(' ', microtime()));
  return sha1($time) . substr(md5($time), 4);
}
session_start();
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest' && isset($_POST['action'])) {
  $app = new App($config['root'], isset($_POST['path']) ? $_POST['path'] : $config['root']);
  header('Content-Type: application/json');
  if ($_POST['action'] != 'login' && isset($config['password']) && !$_SESSION['token']) {
    echo json_encode(array('error' => "Error no Token"));
  } if ($_POST['action'] == 'login') {
    if ($_POST['password'] == $config['password']) {
      $_SESSION['token'] = token();
      echo json_encode(array("result" => $_SESSION['token']));
    } else {
      echo json_encode(array("error" => "Wrong password"));
    }
  } else if (isset($config['password']) &&
             (isset($_SESSION['token']) && $_SESSION['token'] == $_POST['token']) ||
             !isset($config['password'])) {
    if ($_POST['action'] == 'shell') {
      try {
        echo json_encode($app->shell($_POST['cmd']));
      } catch(Exception $e) {
        echo json_encode(array("error" => $e->getMessage()));
      }
    }
  }
  exit();
}


?>
<!DOCTYPE html>
<html>
<head>
<title>PHP Shell</title>
<link rel="shortcut icon" href="https://raw.githubusercontent.com/jcubic/jquery.terminal-www/master/favicon.ico"/>
<script src="https://code.jquery.com/jquery-3.2.1.min.js"></script>
<script src="https://cdn.rawgit.com/jcubic/jquery.terminal/master/js/jquery.terminal.min.js"></script>
<script src="https://cdn.rawgit.com/jcubic/jquery.terminal/master/js/unix_formatting.js"></script>
<link href="https://cdn.rawgit.com/jcubic/jquery.terminal/master/css/jquery.terminal.min.css"
      rel="stylesheet"/>
<style>
body {
    min-height: 100vh;
    margin: 0;
}
</style>
<body>
<script>
$(function() {
  var config = <?= json_encode(array_merge($config, array('password' => isset($config['password'])))) ?>;
  var cwd = config.root;
  function init(term, token) {
    term.set_mask(false).push(function(cmd) {
      var payload = {cmd: cmd, action: 'shell', path: cwd};
      if (config.password) {
        if (token) {
          payload.token = token;
        } else {
          term.error('no token');
          return;
        }
      }
      term.pause();
      $.post('', payload).then(function(data) {
        if (data.error) {
          term.error(data.error);
        } else {
          term.echo(data.output.trim());
          cwd = data.cwd;
        }
        term.resume();
      });
    }, {
      prompt: function(set) {
        set(cwd + '# ');
      },
      onExit: function() {
        $.Storage.remove('token');
      }
    });
  }
  $('body').terminal(function(password, term) {
    term.pause();
    $.post('', {action: 'login', password: password}).then(function(data) {
      if (data.error) {
        term.error(data.error);
      } else {
        if (config.storage) {
          $.Storage.set('token', data.result);
        }
        init(term, data.result);
      }
      term.resume();
    });
  }, {
    greetings: 'jsh shell',
    prompt: 'password: ',
    onInit: function(term) {
      term.set_mask(true);
      if (config.password) {
        if (config.storage && $.Storage.get('token')) {
          init(term, $.Storage.get('token'));
        }
      } else {
        init(term);
      }
    }
  });
});
</script>
</body>
