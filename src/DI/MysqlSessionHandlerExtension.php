<?php

namespace Wincorex\Session\DI;

use Nette;


class MysqlSessionHandlerExtension extends Nette\DI\CompilerExtension
{
	/** @var array */
	private $default_values = [
		'tableName' => 'web_session',
		'jsonDebug' => FALSE,
		'lockTimeout' => 5,
		'unchangedUpdateDelay' => 300,
	];

	public function loadConfiguration()
	{
		parent::loadConfiguration();

		$config = $this->getConfig($this->default_values);

		$builder = $this->getContainerBuilder();

		$definition = $builder->addDefinition($this->prefix('sessionHandler'))
			->setClass('Wincorex\Session\MysqlSessionHandler')
			->addSetup('setTableName', [$config['tableName']])
			->addSetup('setJsonDebug', [$config['jsonDebug']])
			->addSetup('setLockTimeout', [$config['lockTimeout']])
			->addSetup('setUnchangedUpdateDelay', [$config['unchangedUpdateDelay']]);
		;

		/** @var Nette\DI\ServiceDefinition $sessionDefinition */
		$sessionDefinition = $builder->getDefinition('session');

		$sessionSetup = $sessionDefinition->getSetup();
		# Prepend setHandler method to other possible setups (setExpiration) which would start session prematurely
		array_unshift($sessionSetup, new Nette\DI\Statement('setHandler', array($definition)));

		$sessionDefinition->setSetup($sessionSetup);
	}
}
