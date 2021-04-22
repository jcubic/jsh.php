<?php
/*
 * Single file, terminal like php shell version 0.2.4
 *
 * https://github.com/jcubic/jsh.php
 *
 * Copyright (c) 2017-2021 Jakub T. Jankiewicz <https://jcubic.pl/me>
 * Released under the MIT license
 */
define('VERSION', '0.2.4');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);

// leave blank or delete if don't want password protection
$config = array(
    'password' => 'admin',
    'root' => getcwd(),
    'storage' => true,
    'is_windows' => strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
);

class App {
    function __construct($root, $path, $config) {
        $this->config = (object)$config;
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
    public function sqlite_query($filename, $query) {
        $db = new PDO('sqlite:' . $filename);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $res = $db->query($query);
        if ($res) {
            if (preg_match("/^\s*INSERT|UPDATE|DELETE|ALTER|CREATE|DROP/i", $query)) {
                return $res->rowCount();
            } else {
                return $res->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            throw new Exception("Coudn't open file");
        }
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
        if ($this->config->is_windows) {
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
        $pre = ". .bashrc\nexport HOME=\"$home\"\ncd $path;\n";
        $post = ";echo -n \"$marker\";pwd";
        $command = escapeshellarg($pre . $command . $post);
        $command = $this->unbuffer('/bin/bash -c ' . $command . ' 2>&1', $shell_fn);
        $result = $this->$shell_fn($command);
        /*
        return array(
            'output' => $command,
            'cwd' => $this->path
        );
        */
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
    public function dir($path) {
        // using shell since php can restric to read files from specific directories
        $EXEC = 'X';
        $DIR = 'D';
        $FILE = 'F';
        // depend on GNU version of find (not tested on different versions)
        $cmd = "find . -mindepth 1 -maxdepth 1 \\( -type f -executable -printf ".
               "'$EXEC%p\\0' \\)  -o -type d -printf '$DIR%p\\0' -o \\( -type l -x".
               "type d -printf '$DIR%p\\0' \\) -o -not -type d -printf '$FILE%p\\0'";
        $this->path = $path;
        $result = $this->shell($cmd);
        $files = array();
        $dirs = array();
        $execs = array();
        foreach (explode("\x0", $result['output']) as $item) {
            if ($item != "") {
                $mnemonic = substr($item, 0, 1);
                $item = substr($item, 3); // remove `<MENEMONIC>./'
                switch ($mnemonic) {
                    case $EXEC:
                        $execs[] = $item; // executables are also files
                    case $FILE:
                        $files[] = $item;
                        break;
                    case $DIR:
                        $dirs[] = $item;
                }
            }
        }
        return array(
            'files' => $files,
            'dirs' => $dirs,
            'execs' => $execs
        );
    }
    // ------------------------------------------------------------------------
    public function executables() {
        if (!$this->config->is_windows) {
            $command = 'IFS=":";for i in $PATH; do test -d "$i" && find "$i" ' .
                       '-maxdepth 1 -executable -type f -exec basename {} \;;' .
                       'done | sort | uniq';
            $result = $this->shell($command);
            $commands = explode("\n", trim($result['output']));
            return array_values(array_filter($commands, function($command) {
                return strlen($command) > 1; // filter out . : [
            }));
        }
        return array();
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

$path = isset($_POST['path']) ? $_POST['path'] : $config['root'];
$app = new App($config['root'], $path, $config);
try {
    $config['executables'] = $app->executables();
} catch(Exception $e) {
    $config['executables'] = array();
}

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest' && isset($_POST['action'])) {

    if (!file_exists('.bashrc')) {
        $bashrc = <<<EOF
shopt -s expand_aliases

# man output formatting
export MAN_KEEP_FORMATTING=1
export PATH=\$PATH:/usr/games
export TERM="xterm-256" #force colors for dircolors
alias grep="grep --color=always"

if [ -x /usr/bin/dircolors ]; then
    #Nice colors
    eval "`dircolors -b`"
    alias ls="ls --color=always"
fi
EOF;
        $f = fopen('.bashrc', 'w');
        fwrite($f, $bashrc);
        fclose($f);
    }

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
                     term.echo('Query OK, ' + result + ' row' + (result != 1 ? 's' : '') + ' affected', {
                         formatters: false
                     });
                     break;
                 default:
                     // should not happen
                     term.echo(result);
             }
             term.resume();
         }
         // -------------------------------------------------------------------------
         function sql_formatter(keywords, tables, color) {
             var tables_re = new RegExp('^' + tables.map($.terminal.escape_regex).join('|') + '$', 'i');
             var keywords_re = new RegExp('^' + keywords.join('|') + '$', 'i');
             return function(string) {
                 return string.split(/((?:\s|&nbsp;|\)|\()+)/).map(function(string) {
                     if (tables_re.test(string)) {
                         return '[[u;;]' + string + ']';
                     } else if (keywords_re.test(string)) {
                         return '[[b;' + color + ';]' + string + ']';
                     } else {
                         return string;
                     }
                 }).join('');
             };
         }
         // -------------------------------------------------------------------------
         function mysql_keywords() {
             // mysql keywords from
             // http://dev.mysql.com/doc/refman/5.1/en/reserved-words.html
             var uppercase = [
                 'ACCESSIBLE', 'ADD', 'ALL', 'ALTER', 'ANALYZE', 'AND', 'AS', 'ASC',
                 'ASENSITIVE', 'BEFORE', 'BETWEEN', 'BIGINT', 'BINARY', 'BLOB',
                 'BOTH', 'BY', 'CALL', 'CASCADE', 'CASE', 'CHANGE', 'CHAR',
                 'CHARACTER', 'CHECK', 'COLLATE', 'COLUMN', 'CONDITION',
                 'CONSTRAINT', 'CONTINUE', 'CONVERT', 'CREATE', 'CROSS',
                 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER',
                 'CURSOR', 'DATABASE', 'DATABASES', 'DAY_HOUR', 'DAY_MICROSECOND',
                 'DAY_MINUTE', 'DAY_SECOND', 'DEC', 'DECIMAL', 'DECLARE', 'DEFAULT',
                 'DELAYED', 'DELETE', 'DESC', 'DESCRIBE', 'DETERMINISTIC',
                 'DISTINCT', 'DISTINCTROW', 'DIV', 'DOUBLE', 'DROP', 'DUAL', 'EACH',
                 'ELSE', 'ELSEIF', 'ENCLOSED', 'ESCAPED', 'EXISTS', 'EXIT',
                 'EXPLAIN', 'FALSE', 'FETCH', 'FLOAT', 'FLOAT4', 'FLOAT8', 'FOR',
                 'FORCE', 'FOREIGN', 'FROM', 'FULLTEXT', 'GRANT', 'GROUP', 'HAVING',
                 'HIGH_PRIORITY', 'HOUR_MICROSECOND', 'HOUR_MINUTE', 'HOUR_SECOND',
                 'IF', 'IGNORE', 'IN', 'INDEX', 'INFILE', 'INNER', 'INOUT',
                 'INSENSITIVE', 'INSERT', 'INT', 'INT1', 'INT2', 'INT3', 'INT4',
                 'INT8', 'INTEGER', 'INTERVAL', 'INTO', 'IS', 'ITERATE', 'JOIN',
                 'KEY', 'KEYS', 'KILL', 'LEADING', 'LEAVE', 'LEFT', 'LIKE', 'LIMIT',
                 'LINEAR', 'LINES', 'LOAD', 'LOCALTIME', 'LOCALTIMESTAMP', 'LOCK',
                 'LONG', 'LONGBLOB', 'LONGTEXT', 'LOOP', 'LOW_PRIORITY',
                 'MASTER_SSL_VERIFY_SERVER_CERT', 'MATCH', 'MEDIUMBLOB', 'MEDIUMINT',
                 'MEDIUMTEXT', 'MIDDLEINT', 'MINUTE_MICROSECOND', 'MINUTE_SECOND',
                 'MOD', 'MODIFIES', 'NATURAL', 'NOT', 'NO_WRITE_TO_BINLOG', 'NULL',
                 'NUMERIC', 'ON', 'OPTIMIZE', 'OPTION', 'OPTIONALLY', 'OR', 'ORDER',
                 'OUT', 'OUTER', 'OUTFILE', 'PRECISION', 'PRIMARY', 'PROCEDURE',
                 'PURGE', 'RANGE', 'READ', 'READS', 'READ_WRITE', 'REAL',
                 'REFERENCES', 'REGEXP', 'RELEASE', 'RENAME', 'REPEAT', 'REPLACE',
                 'REQUIRE', 'RESTRICT', 'RETURN', 'REVOKE', 'RIGHT', 'RLIKE',
                 'SCHEMA', 'SCHEMAS', 'SECOND_MICROSECOND', 'SELECT', 'SENSITIVE',
                 'SEPARATOR', 'SET', 'SHOW', 'SMALLINT', 'SPATIAL', 'SPECIFIC',
                 'SQL', 'SQLEXCEPTION', 'SQLSTATE', 'SQLWARNING', 'SQL_BIG_RESULT',
                 'SQL_CALC_FOUND_ROWS', 'SQL_SMALL_RESULT', 'SSL', 'STARTING',
                 'STRAIGHT_JOIN', 'TABLE', 'TERMINATED', 'THEN', 'TINYBLOB',
                 'TINYINT', 'TINYTEXT', 'TO', 'TRAILING', 'TRIGGER', 'TRUE', 'UNDO',
                 'UNION', 'UNIQUE', 'UNLOCK', 'UNSIGNED', 'UPDATE', 'USAGE', 'USE',
                 'USING', 'UTC_DATE', 'UTC_TIME', 'UTC_TIMESTAMP', 'VALUES',
                 'VARBINARY', 'VARCHAR', 'VARCHARACTER', 'VARYING', 'WHEN', 'WHERE',
                 'WHILE', 'WITH', 'WRITE', 'XOR', 'YEAR_MONTH', 'ZEROFILL'];
             return uppercase;
         }
         // -------------------------------------------------------------------------
         function sqlite_keywords() {
             // sqlite keywords taken from
             // https://www.sqlite.org/lang_keywords.html
             var uppercase = [
                 'ABORT', 'ACTION', 'ADD', 'AFTER', 'ALL', 'ALTER', 'ANALYZE', 'AND',
                 'AS', 'ASC', 'ATTACH', 'AUTOINCREMENT', 'BEFORE', 'BEGIN',
                 'BETWEEN', 'BY', 'CASCADE', 'CASE', 'CAST', 'CHECK', 'COLLATE',
                 'COLUMN', 'COMMIT', 'CONFLICT', 'CONSTRAINT', 'CREATE', 'CROSS',
                 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'DATABASE',
                 'DEFAULT', 'DEFERRABLE', 'DEFERRED', 'DELETE', 'DESC', 'DETACH',
                 'DISTINCT', 'DROP', 'EACH', 'ELSE', 'END', 'ESCAPE', 'EXCEPT',
                 'EXCLUSIVE', 'EXISTS', 'EXPLAIN', 'FAIL', 'FOR', 'FOREIGN', 'FROM',
                 'FULL', 'GLOB', 'GROUP', 'HAVING', 'IF', 'IGNORE', 'IMMEDIATE',
                 'IN', 'INDEX', 'INDEXED', 'INITIALLY', 'INNER', 'INSERT', 'INSTEAD',
                 'INTERSECT', 'INTO', 'IS', 'ISNULL', 'JOIN', 'KEY', 'LEFT', 'LIKE',
                 'LIMIT', 'MATCH', 'NATURAL', 'NO', 'NOT', 'NOTNULL', 'NULL', 'OF',
                 'OFFSET', 'ON', 'OR', 'ORDER', 'OUTER', 'PLAN', 'PRAGMA', 'PRIMARY',
                 'QUERY', 'RAISE', 'RECURSIVE', 'REFERENCES', 'REGEXP', 'REINDEX',
                 'RELEASE', 'RENAME', 'REPLACE', 'RESTRICT', 'RIGHT', 'ROLLBACK',
                 'ROW', 'SAVEPOINT', 'SELECT', 'SET', 'TABLE', 'TEMP', 'TEMPORARY',
                 'THEN', 'TO', 'TRANSACTION', 'TRIGGER', 'UNION', 'UNIQUE', 'UPDATE',
                 'USING', 'VACUUM', 'VALUES', 'VIEW', 'VIRTUAL', 'WHEN', 'WHERE',
                 'WITH', 'WITHOUT'];
             return uppercase;
         }
         // ----------------------------------------------------------------------------------------
         function values(arr) {
             if (!arr.length) {
                 return arr;
             }
             var keys = Object.keys(arr[0]);
             return arr.map(function(row) {
                 return Object.keys(row).map(function(key) {
                     return row[key];
                 });
             });
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
             var prompt = '[[b;#55f;]mysql]> ';
             function push(id, tables) {
                 tables = values(tables).map(function(row) {
                     return row[0];
                 });
                 var keywords = mysql_keywords();
                 term.resume().push(function(query) {
                     term.pause();
                     rpc('mysql_query', [id, query]).then(sql_result);
                 }, {
                     name: 'mysql',
                     prompt: prompt,
                     completion: keywords.concat(tables),
                     formatters: [sql_formatter(keywords, tables, 'white')],
                     onExit: function() {
                         if (config.storage) {
                             $.Storage.remove('mysql_connection');
                         }
                         rpc('mysql_close', [id]);
                     }
                 });
             }
             rpc('mysql_connect', params).then(function(id) {
                 if (config.storage) {
                     $.Storage.set('mysql_connection', id);
                 }
                 rpc('mysql_query', [id, 'show tables']).then(push.bind(null, id));
             });
         }
         // ----------------------------------------------------------------------------------------
         function sqlite(filename) {
             var query = 'SELECT name FROM sqlite_master WHERE type = "table"';
             var keywords = sqlite_keywords();
             var prompt = '[[b;#55f;]sqlite]> ';
             function push(tables) {
                 tables = values(tables).map(function(row) {
                     return row[0];
                 });
                 term.push(function(query) {
                     if (query.match(/^\s*help\s*$/)) {
                         term.echo('show tables:\n\tSELECT name FROM sqlite_m'+
                                   'aster WHERE type = "table"\ndescribe tabl'+
                                   'e:\n\tPRAGMA table_info([TABLE NAME])');
                     } else {
                         term.pause();
                         rpc('sqlite_query', [filename, query]).then(sql_result);
                     }
                 }, {
                     name: 'sqlite',
                     prompt: prompt,
                     completion: ['help'].concat(keywords).concat(tables),
                     formatters: [sql_formatter(keywords, tables, 'white')]
                 }).resume();
             }
             term.pause();
             rpc('sqlite_query', [filename, query]).then(push);
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
         var dir;
         // ----------------------------------------------------------------------------------------
         function refresh_dir() {
             return rpc('dir', [cwd]).then(function(result) {
                 dir = result;
                 return result;
             });
         }
         // ----------------------------------------------------------------------------------------
         var commands = {
             mysql: function(cmd) {
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
             },
             sqlite: function(cmd) {
                 if (cmd.args.length === 1) {
                     var fname;
                     if (cmd.args[0].match(/^\//)) {
                         fname = cmd.args[0];
                     } else {
                         fname = cwd + '/' + cmd.args[0];
                     }
                     sqlite(fname);
                 } else {
                     term.echo('sqlite <FILENAME>');
                 }
             },
             rpc: function(cmd) {
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
                             prompt: '[[;#D72424;]rpc]> '
                         });
                     }
                     term.resume();
                 });
             }
         };
         // ----------------------------------------------------------------------------------------
         function shell(command) {
             var payload = {cmd: command, action: 'shell', path: cwd};
             if (config.password) {
                 if (token) {
                     payload.token = token;
                 } else {
                     term.error('no token');
                     return;
                 }
             }
             return $.post('', payload);
         }
         var builtins = Object.keys(commands);
         // ----------------------------------------------------------------------------------------
         (function(push) {
             refresh_dir().then(push);
         })(function() {
             term.resume().set_mask(false).push(function(command) {
                 var cmd = $.terminal.parse_command(command);
                 if (typeof commands[cmd.name] == 'function') {
                     commands[cmd.name](cmd);
                 } else {
                     term.pause();
                     shell(command).then(function(data) {
                         if (data.error) {
                             term.error(data.error).resume();
                         } else {
                             term.echo(data.output.trim(), {exec: false});
                             cwd = data.cwd;
                             refresh_dir().then(term.resume);
                         }
                     });
                 }
             }, {
                 prompt: function(set) {
                     set(cwd + '# ');
                 },
                 onExit: function() {
                     $.Storage.remove('token');
                 },
                 completion: function(string, callback) {
                     var command = this.get_command();
                     var re = new RegExp('^\\s*' + $.terminal.escape_regex(string));
                     var cmd = $.terminal.parse_command(command);
                     function dirs_slash(dir) {
                         return (dir.dirs || []).map(function(dir) {
                             return dir + '/';
                         });
                     }
                     function fix_spaces(array) {
                         if (string.match(/^"/)) {
                             return array.map(function(item) {
                                 return '"' + item;
                             });
                         } else {
                             return array.map(function(item) {
                                 return item.replace(/([() ])/g, '\\$1');
                             });
                         }
                     }
                     if (!config.is_windows) {
                         var execs = dir.execs.concat(config.executables);
                         if (string.match(/^\$/)) {
                             shell('env').then(function(result) {
                                 callback(result.output.split('\n').map(function(pair) {
                                     return '$' + pair.split(/=/)[0];
                                 }));
                             });
                         } else if (command.match(re) || command === '') {
                             callback(builtins.concat(execs));
                         } else {
                             var m = string.match(/(.*)\/([^\/]+)/);
                             var is_dir_command = cmd.name == 'cd';
                             var path;
                             if (is_dir_command) {
                                 if (m) {
                                     path = cwd + '/' + m[1];
                                     rpc('dir', [path]).then(function(result) {
                                         var dirs = (result.dirs || []).map(function(dir) {
                                             return m[1] + '/' + dir + '/';
                                         });
                                         callback(dirs);
                                     });
                                 } else {
                                     callback(dirs_slash(dir));
                                 }
                             } else {
                                 // file command
                                 if (m) {
                                     path = cwd + '/' + m[1];
                                     rpc('dir', [path]).then(function(result) {
                                         var dirs = dirs_slash(result);
                                         var dirs_files = (result.files || [])
                                             .concat(dirs).map(function(file_dir) {
                                                 return m[1] + '/' + file_dir;
                                             });
                                         callback(dirs_files);
                                     });
                                 } else {
                                     var dirs_files = (dir.files || []).concat(dirs_slash(dir));
                                     callback(dirs_files);
                                 }
                             }
                         }
                     } else {
                         callback([]);
                     }
                 }
             });
         });
     }
     var init_formatters = $.terminal.defaults.formatters;
     var formatters_stack = [init_formatters];
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
         }).catch(function(error) {
            term.error(error.message);
         });
     }, {
         extra: {
             formatters: init_formatters
         },
         onPush: function(before, after) {
             var formatters = init_formatters.slice().concat(after.formatters || []);
             $.terminal.defaults.formatters = formatters;
             formatters_stack.push(formatters);
         },
         onPop: function(before, after) {
             formatters_stack.pop();
             if (formatters_stack.length > 0) {
                 var last = formatters_stack[formatters_stack.length-1];
                 $.terminal.defaults.formatters = last;
             }
         },
         greetings: 'jsh shell v. <?= VERSION ?>',
         prompt: 'password: ',
         onInit: function(term) {
             term.set_mask(true);
             if (config.password) {
                 if (config.storage && $.Storage.get('token')) {
                     term.pause();
                     init(term, $.Storage.get('token'));
                 }
             } else {
                term.pause();
                init(term);
             }
         }
     });
 });
</script>
</body>
<?php } ?>
