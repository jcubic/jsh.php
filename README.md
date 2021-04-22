## jsh.php version 0.2.4

[Single file, terminal like php shell (PHP web terminal emulator)](https://github.com/jcubic/jsh.php)

## Features

sqlite, mysql command with syntax highlight and tab completion

## Limitations

* Windows support is limited, you can run shell commands but it run normal cmd process (no powershell)
* tab completion for commands in the system works only on Unix systems.

## Changelog

### 0.2.4
* fix authentication issue

### 0.2.3
* remove bash debugger

### 0.2.2
* fix error from running compgen command as shell command (disable exec in jQuery Terminal echo method)

### 0.2.1
* fix SELinux trigger by running compgen

### 0.2.0
* sqlite and mysql commands
* completion
* first tag

## License

Released under [MIT](http://opensource.org/licenses/MIT) license

Copyright (c) 2017-2019 [Jakub Jankiewicz](https://jcubic.pl/jakub-jankiewicz)

