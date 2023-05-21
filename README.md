# MySQL Session handler

Custom PHP session handler for [Nette Framework](http://nette.org/) that uses MySQL database for storage.

## Requirements

- [nette/database](https://github.com/nette/database) 2.4+
- PHP 7.2+

## Installation

Preferred way to install wincorex/mysql-session-handler is by using [Composer](http://getcomposer.org/):

```sh
$ composer require wincorex/mysql-session-handler:~dev-master
```

## Setup

After installation:

1) Create the table sessions using SQL in [sql/create.sql](sql/create.sql).

2) Register an extension in config.neon:

```neon
	extensions:
		sessionHandler: Wincorex\Session\DI\MysqlSessionHandlerExtension

	sessionHandler:
		tableName: 'web_session'
		jsonDebug: true
```

## Original source code
- Pematon [GitHub](https://github.com/pematon/mysql-session-handler)
- MD5 hash is not applicated for easy debugging

## Features

- For security reasons, Session ID is stored in the database as an MD5 hash.
- Multi-Master Replication friendly (tested in Master-Master row-based replication setup).
