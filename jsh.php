<?php
/*
 * Single file, terminal like php shell
 *
 * https://github.com/jcubic/jsh.php
 *
 * Copyright (c) 2017-2018 Jakub Jankiewicz <http://jcubic.pl/me>
 * Released under the MIT license
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// leave blank or delete if don't want password protection
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
    public function uniq_id() {
        return md5(array_sum(explode(" ", microtime())) * mktime());
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
    public function mysql_close($id) {
        if (isset($_SESSION['mysql']) && isset($_SESSION['mysql'][$id])) {
            unset($_SESSION['mysql'][$id]);
            return true;
        }
        return false;
    }
    // ------------------------------------------------------------------------
    public function mysql_connect($username, $password, $db_name, $host = 'localhost') {
        $db = $this->_mysql_connect($username, $password, $db_name, $host);
        if (!isset($_SESSION['mysql'])) {
            $_SESSION['mysql'] = array();
        }
        $resource_id = $this->uniq_id();
        $_SESSION['mysql'][$resource_id] = array(
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'db' => $db_name
        );
        return $resource_id;
    }
    // ------------------------------------------------------------------------
    public function mysql_query($resource_id, $query) {
        if (!isset($_SESSION['mysql'])) {
            throw new Exception("Not mysql sessions found");
        }
        if (!isset($_SESSION['mysql'][$resource_id])) {
            throw new Exception("Invalid resource id");
        }
        $data = $_SESSION['mysql'][$resource_id];
        $db = $this->_mysql_connect($data['username'], $data['password'], $data['db'], $data['host']);
        $res = $db->query($query);
        if ($res) {
            if (preg_match("/^\s*INSERT|UPDATE|DELETE|ALTER|CREATE|DROP/i", $query)) {
                return $res->rowCount();
            } else {
                    return $res->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            throw new Exception("Query Error");
        }
    }
    // ------------------------------------------------------------------------
    private function _mysql_connect($username, $password, $db, $host = 'localhost') {
      $opts = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);
      $db = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $username, $password, $opts);
      return $db;
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
    // ------------------------------------------------------------------------
    public function command($command, $path, $shell_fn) {
        if (!method_exists($this, $shell_fn)) {
            throw new Exception("Invalid shell '$shell_fn'");
        }
        $marker = 'XXXX' . md5(time());
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $pre = "@echo off\ncd /D $path\n";
            $post = "\necho '$marker'%cd%";
            $command = $pre . $command . $post;
            $file_name = "tmp.bat";
            $file = fopen($file_name, "w");
            fwrite($file, $command);
            fclose($file);
            $result = preg_replace("/\r/", "", $this->$shell_fn($file_name));
            unlink($file_name);
            $result = sapi_windows_cp_conv(sapi_windows_cp_get('oem'), 65001, $result);
            $output = preg_split("/\n?'".$marker."'/", $result);
            if (count($output) == 2) {
                $cwd = preg_replace("/\n$/", '', $output[1]);
            } else {
                $cwd = $path;
            }
            return array(
                'output' => preg_replace("/\n$/", "", $output[0]),
                'cwd' => $cwd
            );
        }
        $command = preg_replace("/&\s*$/", ' >/dev/null & echo $!', $command);
        $home = $this->root;
        $cmd_path = __DIR__;
        $pre = "export HOME=\"$home\"\ncd $path;\n";
        $post = ";echo -n \"$marker\";pwd";
        $command = escapeshellarg($pre . $command . $post);
        $command = $this->unbuffer('/bin/bash -c ' . $command . ' 2>&1', $shell_fn);
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
            $output[0] = preg_replace("/\n$/", "", $output[0]);
            return array(
                'output' => preg_replace("/" . preg_quote($post) . "/", "", $output[0]),
                'cwd' => $cwd
            );
        } else {
            throw new Exception("Internal error, shell function give no result");
        }
    }
    // ------------------------------------------------------------------------
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
function password_set() {
    global $config;
    return isset($config['password']) && $config['password'] != '';
}
session_start();
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest' && isset($_POST['action'])) {
    $app = new App($config['root'], isset($_POST['path']) ? $_POST['path'] : $config['root']);
    header('Content-Type: application/json');
    if ($_POST['action'] != 'login' && password_set() && !isset($_SESSION['token'])) {
        echo json_encode(array('error' => "Error no Token"));
    } if ($_POST['action'] == 'login') {
        if ($_POST['password'] == $config['password']) {
            $_SESSION['token'] = token();
            echo json_encode(array("result" => $_SESSION['token']));
        } else {
            echo json_encode(array("error" => "Wrong password"));
        }
    } else if (password_set() &&
               (isset($_SESSION['token']) && isset($_POST['token']) &&
                $_SESSION['token'] == $_POST['token']) ||
               !password_set()) {
        if ($_POST['action'] == 'shell') {
            try {
                echo json_encode($app->shell($_POST['cmd']));
            } catch(Exception $e) {
                echo json_encode(array("error" => $e->getMessage()));
            }
        } else if ($_POST['action'] == 'rpc' && isset($_POST['method'])) {
            $class = get_class($app);
            $methods = get_class_methods($class);
            if ($_POST['method'] == 'system.describe') {
                echo json_encode(array('result' => array_values(array_filter(array_map(function($name) use ($class) {
                  $method = new ReflectionMethod($class, $name);
                  if ($method->isPublic() && !$method->isStatic() &&
                      !$method->isConstructor() && !$method->isDestructor()) {
                    return array('name' => $name, 'params' => $method->getNumberOfRequiredParameters());
                  }
                }, $methods)))));
            } else {
                try {
                    $params = isset($_POST['params']) ? $_POST['params'] : array();
                    if (!in_array($_POST['method'], $methods) && !in_array("__call", $methods)) {
                        echo json_encode(array("error" => "Method {$_POST['method']} not found"));
                    } else if (in_array("__call", $methods) && !in_array($method, $methods)) {
                        $result = call_user_func_array(array($obj, $_POST['method']), $params);
                        echo json_encode(array('result' => $result));
                    } else {
                        $method_object = new ReflectionMethod($class, $_POST['method']);
                        $num_got = count($params);
                        $num_expect = $method_object->getNumberOfRequiredParameters();
                        if ($num_got != $num_expect) {
                            $msg = "Wrong number of parameters in `{$_POST['method']}' method. Got " .
                                   "$num_got expect $num_expect";
                            echo json_encode(array("error" => $msg));
                        } else {
                            $result = call_user_func_array(array($app, $_POST['method']), $params);
                            echo json_encode(array('result' => $result));
                        }
                    }
                } catch (Exception $e) {
                    echo json_encode(array('error' => $e->getMessage()));
                }
            }
        }
    } else {
        echo json_encode(array('error' => 'Invalid Token'));
    }
} else {
?>
<!DOCTYPE html>
<html>
<head>
<title>PHP Shell</title>
<link rel="shortcut icon" href="https://raw.githubusercontent.com/jcubic/jquery.terminal-www/master/favicon.ico"/>
<script src="https://code.jquery.com/jquery-3.2.1.min.js"></script>
<script src="https://unpkg.com/jquery.terminal/js/jquery.terminal.min.js"></script>
<script src="https://unpkg.com/jquery.terminal/js/unix_formatting.js"></script>
<script src="https://unpkg.com/jquery.terminal/js/ascii_table.js"></script>
<link href="https://unpkg.com/jquery.terminal/css/jquery.terminal.min.css"
      rel="stylesheet"/>
<style>
body {
    min-height: 100vh;
    margin: 0;
}
</style>
<body>
<script>
 jQuery(function($) {
     var config = <?= json_encode(array_merge($config, array('password' => isset($config['password']) && $config['password'] != ''))) ?>;
     var cwd = config.root;
     // --------------------------------------------------------------------------------------------
     function init(term, token) {
         function sql_result(result) {
             switch ($.type(result)) {
                 case 'array':
                     if (result.length) {
                         var keys = Object.keys(result[0]);
                         result = [keys].concat(result.map(function(row) {
                             if (row instanceof Array) {
                                 return row.map(function(item) {
                                     return String(item);
                                 });
                             } else {
                                 return Object.keys(row).map(function(key) {
                                     return String(row[key]);
                                 });
                             }
                         }));
                         term.echo(ascii_table(result, true), {formatters: false});
                     }
                     break;
                 case 'number':
                     term.echo('Query OK, ' + result + ' row' + (result != 1 ? 's' : '') + ' affected');
                 default:
                     // should not happen
                     term.echo(result);
             }
             term.resume();
         }
         // ----------------------------------------------------------------------------------------
         function mysql(user, password, db, host) {
             var params = [user, password, db];
             if (typeof host !== 'undefiend') {
                 params.push(host);
             }
             term.pause();
             // clear old mysql connection if closed the browser tab while in mysql
             if (config.storage) {
                 var id = $.Storage.get('mysql_connection');
                 if (id) {
                     $.Storage.remove('mysql_connection');
                     rpc('mysql_close', [id]);
                 }
             }
             rpc('mysql_connect', params).then(function(id) {
                 if (config.storage) {
                     $.Storage.set('mysql_connection', id);
                 }
                 term.resume().push(function(query) {
                     term.pause();
                     rpc('mysql_query', [id, query]).then(sql_result);
                 }, {
                     name: 'mysql',
                     prompt: 'mysql> ',
                     onExit: function() {
                         if (config.storage) {
                             $.Storage.remove('mysql_connection');
                         }
                         rpc('mysql_close', [id]);
                     }
                 });
             });
         }
         // ----------------------------------------------------------------------------------------
         function rpc(method, params) {
             if (typeof params === 'undefined') {
                 params = [];
             }
             var payload = {action: 'rpc', method: method, params: params};
             if (config.password) {
                 if (token) {
                     payload.token = token;
                 } else {
                     term.error('no token');
                     return;
                 }
             }
             var defer = $.Deferred();
             $.post('', payload, function(data) {
                 try {
                     data = JSON.parse(data.replace(/\\r/g, ''));
                     if (data.error) {
                         term.error(data.error).resume();
                     } else {
                         defer.resolve(data.result);
                     }
                 } catch (e) {
                     term.error('JSON parse error').resume();
                 }
             }, 'text');
             return defer.promise();
         }
         // ----------------------------------------------------------------------------------------
         term.set_mask(false).push(function(command) {
             var cmd = $.terminal.parse_command(command);
             if (cmd.name == 'mysql') {
                 var password;
                 cmd.args = cmd.args.filter(function(arg) {
                     var m = arg.match(/^-p(.+)/);
                     if (m) {
                         password = m[1];
                         return false;
                     }
                     return true;
                 });
                 var options = $.terminal.parse_options(cmd.args, {boolean: 'p'});
                 var host;
                 if (options.h) {
                     host = options.h;
                 }
                 if (options.u && options._.length > 0) {
                     if (!password) {
                         term.set_mask(true).read('password: ').then(function(pass) {
                             password = pass;
                             term.set_mask(false);
                             mysql(options.u, password, options._[0], host);
                         });
                     } else {
                         mysql(options.u, password, options._[0], host);
                     }
                 } else {
                     term.echo('usage: mysql -u <USER> -p<PASSWORD> -h <HOST> <DB NAME>');
                 }
             } else if (cmd.name == 'rpc') {
                 term.pause();
                 rpc('system.describe').then(function(data) {
                     if (data.error) {
                         term.error(data.error);
                     } else {
                         term.push(function(command) {
                             cmd = $.terminal.parse_command(command);
                             rpc(cmd.name, cmd.args).then(function(data) {
                                 if (data.error) {
                                     term.error(data.error);
                                 } else {
                                     term.echo(data.result);
                                 }
                             });
                         }, {
                             completion: data.result.map(function(method) { return method.name; }),
                             name: 'rpc',
                             prompt: 'rpc> '
                         });
                     }
                     term.resume();
                 });
             } else {
                 var payload = {cmd: command, action: 'shell', path: cwd};
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
             }
         }, {
             prompt: function(set) {
                 set(cwd + '# ');
             },
             onExit: function() {
                 $.Storage.remove('token');
             }
         });
     }
     // --------------------------------------------------------------------------------------------
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
<?php } ?>
