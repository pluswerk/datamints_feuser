<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Datamints\Feuser\Hook\FlexFormHook;

if (!defined('TYPO3')) {
    die('Access denied.');
}

$extensionName = 'datamints_feuser';
ExtensionManagementUtility::addPItoST43($extensionName, 'pi1/class.tx_datamintsfeuser_pi1.php', '_pi1', 'list_type', false);

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][$extensionName] = FlexFormHook::class;

// Extension Konfiguration auslesen.
$confArray = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$extensionName]);

// Wenn gewünscht Salesforce verwenden.
if ($confArray['enableSalesforce']) {
    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$extensionName]['sendMail']['salesforce'] = 'EXT:' . $extensionName . '/lib/class.tx_datamintsfeuser_salesforce.php:tx_datamintsfeuser_salesforce->main';
}
