<?php

/***************************************************************
 * Extension Manager/Repository config file for ext "FeUserManagementLocal".
 *
 * Auto generated on 22-10-2019 16:41.
 *
 * Manual updates:
 * Only the data in the array - everything else is removed by next
 * writing. "version" and "dependencies" must not be touched!
 ***************************************************************/

assert(!empty($_EXTKEY));
$EM_CONF[$_EXTKEY] = [
    'title' => 'Frontend User Management',
    'description' => 'User registration and edit plugin, fully configurable, custom validators, autologin, double-opt-in, admin approval, IRRE configuration, resend activation mail, redirect features, support for saltedpasswords, support for salesforce. More to come!',
    'category' => 'plugin',
    'version' => '0.12.5',
    'state' => 'beta',
    'author' => 'Bernhard Baumgartl, datamints GmbH',
    'author_email' => 'b.baumgartl@datamints.com',
    'constraints' => [
        'depends' => [
            'php' => '5.3.7-7.99.99',
            'typo3' => '6.2.0-10.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'typo3db_legacy' => '1.0.0-1.0.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Datamints\\Feuser\\' => 'Classes',
        ],
        'classmap' => [
            0 => 'lib',
            1 => 'pi1',
        ],
    ],
];
