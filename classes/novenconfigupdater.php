<?php
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: Noven INI Update
// SOFTWARE RELEASE: @@@VERSION@@@
// COPYRIGHT NOTICE: Copyright (C) @@@YEAR@@@ - Jean-Luc Nguyen, Jerome Vieilledent - Noven.
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//
/**
 * Config.php Update handler
 * @author Jerome Vieilledent
 * @package noveniniupdate
 */
class NovenConfigUpdater extends NovenConfigAbstractUpdater implements INovenFileUpdater
{
	const CONFIG_PHP_FILE = 'config.php';
	
	const CONFIG_TAG_CONSTANT = 'constant',
		  CONFIG_TAG_CUSTOM_CODE = 'customCode';
		  
	/**
	 * Constructor
	 */
	public function __construct()
	{
		parent::__construct();
	}
	
	/**
	 * (non-PHPdoc)
	 * @see extension/noveniniupdate/classes/INovenFileUpdater#setEnv($env)
	 */
	public function setEnv($env, $backup)
	{
		$configTag = $this->xmlDoc->ConfigPHPFile;
		if(count($configTag))
		{
			// First check if environment is supported
			if(!$this->checkIsEnvSupported($env))
			{
				$errMsg = ezi18n('extension/noveniniupdate/error', 'Given environment "%envname" is not supported/declared in XML config file', null, array('%envname' => $env));
				throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::UNSUPPORTED_ENV);
			}
			
			// Do a backup if necessary
			if($backup)
			{
				$this->doBackup(self::CONFIG_PHP_FILE);
			}
			
			// Get config params for given environment
			$aConfigConf = $this->xmlDoc->xpath("//ConfigPHPFile/config[@env='$env']");
			if(!$aConfigConf)
			{
				$errMsg = ezi18n('extension/noveniniupdate/error', 'Config PHP file is not configured for environment "%envname" in XML config file !', null, array('%envname' => $env));
				throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::CONFIG_PHP_NOT_CONFIGURED);
			}
			
			$configConf = $aConfigConf[0];
			$phpGenerator = new ezcPhpGenerator(self::CONFIG_PHP_FILE);
			$phpGenerator->appendComment('Generated by NovenINIUpdate. '.date('Y-m-d H:i'));
			$phpGenerator->appendEmptyLines();
			
			// Append PHP code for each tag
			foreach($configConf->children() as $tag)
			{
				try
				{
					if(isset($tag['comment']))
						$phpGenerator->appendComment((string)$tag['comment']);
					
					switch($tag->getName())
					{
						case self::CONFIG_TAG_CONSTANT:
							$value = (string)$tag['value']; // First cast as string
							$value = (isset($tag['isBoolean']) && $tag['isBoolean'] == 'true') ? (bool)$tag['value'] : (string)$tag['value']; // Then cast as bool if necessary
							$phpGenerator->appendDefine($tag['name'], $value);
						break;
						
						case self::CONFIG_TAG_CUSTOM_CODE:
							$value = trim((string)$tag);
							$phpGenerator->appendCustomCode($value);
						break;
						
						default:
							$errMsg = ezi18n('extension/noveniniupdate/error', 'XML tag "%xmltag" is not supported by Config updater', null, array('%xmltag' => $tag->geName()));
							throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::UNSUPPORTED_XML_TAG);
						break;
					}
					
					$phpGenerator->appendEmptyLines();
				}
				catch(Exception $e)
				{
					eZDebug::writeError($e->getMessage(), 'NovenINIUpdate');
					continue;
				}
			}
			
			// Now store the config.php file
			try
			{
				$phpGenerator->finish();
			}
			catch(ezcPhpGeneratorException $e)
			{
				$errMsg = ezi18n('extension/noveniniupdate/error', 'Write error on file %inifile', null, array('%inifile' => self::CONFIG_PHP_FILE));
				throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::FILE_IO_ERROR);
			}
		}
	}
	
	/**
	 * (non-PHPdoc)
	 * @see extension/noveniniupdate/classes/INovenFileUpdater#getParamsByEnv($env)
	 */
	public function getParamsByEnv($env)
	{
		$aParams = array();
		
		// Check if <ConfigPHPFile> is configured in XML file
		$configTag = $this->xmlDoc->ConfigPHPFile;
		if(count($configTag))
		{
			// First check if environment is supported
			if(!$this->checkIsEnvSupported($env))
			{
				$errMsg = ezi18n('extension/noveniniupdate/error', 'Given environment "%envname" is not supported/declared in XML config file', null, array('%envname' => $env));
				throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::UNSUPPORTED_ENV);
			}
			
			// Get config params for given environment
			$aConfigConf = $this->xmlDoc->xpath("//ConfigPHPFile/config[@env='$env']");
			if(!$aConfigConf)
			{
				$errMsg = ezi18n('extension/noveniniupdate/error', 'Cluster Mode is not configured for environment "%envname" in XML config file !', null, array('%envname' => $env));
				throw new NovenConfigUpdaterException($errMsg, NovenConfigUpdaterException::CLUSTER_NOT_CONFIGURED);
			}
			
			$configConf = $aConfigConf[0];
			foreach($configConf as $tagName => $tag)
			{
				switch($tagName)
				{
					case self::CONFIG_TAG_CONSTANT:
						$name = (string)$tag['name'];
						$value = (string)$tag['value'];
					break;
					
					case self::CONFIG_TAG_CUSTOM_CODE:
					default:
						$name = 'Custom Code';
						$value = trim((string)$tag);
					break;
				}
				
				$aParams[] = array(
					'name'		=> $name,
					'value'		=> (string)$value
				);
			}
		}
		
		return $aParams;
	}
	
	/**
	 * Returns params diff
	 * @param string $env
	 */
	public function getDiffParamsByEnv($env)
	{
		$currentEnv = $this->getCurrentEnvironment();
		$aResult = array(
			'current'	=> $this->getParamsByEnv($currentEnv),
			'new'		=> $this->getParamsByEnv($env) 
		);
		
		return $aResult;
	}
}