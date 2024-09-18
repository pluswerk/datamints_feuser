<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

ExtensionManagementUtility::addStaticFile('datamints_feuser', 'Configuration/TypoScript/', 'Frontend User Management');

// Salesforce
ExtensionManagementUtility::addStaticFile('datamints_feuser', 'Configuration/TypoScript/Salesforce/', 'Frontend User Management (Salesforce)');
