<?php

namespace Wincorex\Session\DI;

use Nette;

class MysqlSessionHandlerExtension extends Nette\DI\CompilerExtension
{
	private $defaults = [
		'tableName' => 'sessions',
		'jsonFormat' => false,
	];

	public function loadConfiguration()
	{
		parent::loadConfiguration();

		$config = $this->getConfig($this->defaults);

		$builder = $this->getContainerBuilder();

		$definition = $builder->addDefinition($this->prefix('sessionHandler'))
			->setClass('Wincorex\Session\MysqlSessionHandler')
			->addSetup('setTableName', [$config['tableName']])
			->addSetup('setJsonFormat', [$config['jsonFormat']])
		;


		$sessionDefinition = $builder->getDefinition('session');
		$sessionSetup = $sessionDefinition->getSetup();
		# Prepend setHandler method to other possible setups (setExpiration) which would start session prematurely
		array_unshift($sessionSetup, new Nette\DI\Statement('setHandler', array($definition)));
		$sessionDefinition->setSetup($sessionSetup);
	}
}
