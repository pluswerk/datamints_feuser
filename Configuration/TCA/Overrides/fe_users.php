<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

if (!defined('TYPO3')) {
    die('Access denied.');
}

$tempColumns = ['gender' => ['exclude' => '1', 'label' => 'LLL:EXT:datamints_feuser/locallang_db.xml:fe_users.gender', 'config' => ['type' => 'radio', 'items' => [['LLL:EXT:datamints_feuser/locallang_db.xml:fe_users.gender.I.0', '0'], ['LLL:EXT:datamints_feuser/locallang_db.xml:fe_users.gender.I.1', '1']]]], 'tx_datamintsfeuser_approval_level' => ['exclude' => '1', 'label' => 'LLL:EXT:datamints_feuser/locallang_db.xml:fe_users.tx_datamintsfeuser_approval_level', 'config' => ['type' => 'input', 'size' => '2', 'eval' => 'int', 'range' => ['upper' => '2', 'lower' => '0'], 'default' => '0']]];

ExtensionManagementUtility::addTCAcolumns('fe_users', $tempColumns);
ExtensionManagementUtility::addToAllTCAtypes('fe_users', 'gender', '', 'before:name');
ExtensionManagementUtility::addToAllTCAtypes('fe_users', '--div--;LLL:EXT:datamints_feuser/locallang_db.xml:tt_content.list_type_pi1, tx_datamintsfeuser_approval_level');
