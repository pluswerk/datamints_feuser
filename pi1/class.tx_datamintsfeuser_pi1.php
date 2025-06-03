<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2009 Bernhard Baumgartl <b.baumgartl@datamints.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Typo3DbLegacy\Database\DatabaseConnection;
use TYPO3\CMS\Core\Context\Exception\AspectPropertyNotFoundException;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\Plugin\AbstractPlugin;
use Datamints\Feuser\Utility\Utils;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use In2code\Powermail\Domain\Service\CalculatingCaptchaService;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInvoker;
use In2code\Powermail\ViewHelpers\Validation\CaptchaViewHelper;
use In2code\Powermail\Domain\Model\Field;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Plugin 'Frontend User Management' for the 'datamints_feuser' extension.
 *
 * @author  Bernhard Baumgartl <b.baumgartl@datamints.com>
 * @package TYPO3
 * @subpackage  tx_datamintsfeuser
 */
class tx_datamintsfeuser_pi1 extends AbstractPlugin
{
    /**
     * @var TypoScriptFrontendController
     */
    protected $frontendController;

    protected DatabaseConnection $databaseConnection;

    protected \Datamints\Feuser\Domain\Repository\FeUserRepository $repository;

    protected $templateService;

    private ?object $pageRepository = null;

    public $extKey = 'datamints_feuser';

    public $prefixId = 'tx_datamintsfeuser_pi1';

    public $scriptRelPath = 'pi1/class.tx_datamintsfeuser_pi1.php';

    public $conf = [];

    public $lang = [];

    public $extConf = [];

    public $feUsersTca = [];

    public $userId = 0;

    public $contentId = 0;

    public $storagePageId = 0;

    public $arrUsedFields = [];

    public $arrUniqueFields = [];

    public $arrRequiredFields = [];

    public $arrHiddenParams = [];

    const modeKeySend = 'send';

    const modeKeyApprovalcheck = 'approvalcheck';

    const modeKeyResendactivation = 'resendactivation';

    const submodeKeySent = 'sent';

    const submodeKeyError = 'error';

    const submodeKeyFailure = 'failure';

    const submodeKeySuccess = 'success';

    const submodeKeyUserdelete = 'userdelete';

    const showtypeKeyEdit = 'edit';

    const showtypeKeyRegister = 'register';

    const validationerrorKeySize = 'size';

    const validationerrorKeyType = 'type';

    const validationerrorKeyEqual = 'equal';

    const validationerrorKeyValid = 'valid';

    const validationerrorKeyDelete = 'delete';

    const validationerrorKeyLength = 'length';

    const validationerrorKeyUnique = 'unique';

    const validationerrorKeyUpload = 'upload';

    const validationerrorKeyRequired = 'required';

    const submitparameterKeyHash = 'hash';

    const submitparameterKeyMode = 'submit';

    const submitparameterKeyPage = 'pageid';

    const submitparameterKeyUser = 'userid';

    const submitparameterKeySubmode = 'submitmode';

    const specialfieldKeySubmit = 'submit';

    const specialfieldKeyCaptcha = 'captcha';

    const specialfieldKeyInfoitem = 'infoitem';

    const specialfieldKeySeparator = 'separator';

    const specialfieldKeyUserdelete = 'userdelete';

    const specialfieldKeyResendactivation = 'resendactivation';

    const specialfieldKeyPasswordconfirmation = 'passwordconfirmation';

    private readonly Utils $utils;

    private readonly LanguageAspect $languageAspect;

    public function __construct($_ = null, ?TypoScriptFrontendController $frontendController = null)
    {
        parent::__construct($_, $frontendController);
        $this->utils = GeneralUtility::makeInstance(Utils::class);
        $context = GeneralUtility::makeInstance(Context::class);
        assert($context instanceof Context);
        $languageAspect = $context->getAspect('language');
        assert($languageAspect instanceof LanguageAspect);
        $this->languageAspect = $languageAspect;
        //$this->databaseConnection = GeneralUtility::makeInstance(DatabaseConnection::class);
    }

    /**
     * The main method of the PlugIn
     *
     * @param string $content
     * @param array $conf
     * @return  string      $content
     */
    public function main($content, $conf)
    {
        $this->conf = $conf;

        $this->frontendController = $this->frontendController ?: $GLOBALS['TSFE'];
        $this->templateService = $this->templateService ?: $this->cObj;

        $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);


        // Debug.
        //      $this->frontendController->set_no_cache();
        //      $this->databaseConnection->debugOutput = TRUE;

        // PiVars und Flexform laden.
        $this->pi_setPiVarDefaults();
        $this->pi_initPIflexForm();

        // Erst die Konfiguration und dann die Labels laden, damit die in der Flexform gesetzten Labels auch beruecksichtigt werden!
        $this->determineConfiguration();
        $this->pi_loadLL();

        // ToDo: Bessere Lösung für das Problem, dass ein Label nur zum LOCAL_LANG Array hinzugefügt wird, wenn die Sprache bereits im Array vorhanden ist!
        if (is_array($this->conf['_LOCAL_LANG.'])) {
            foreach (GeneralUtility::removeDotsFromTS($this->conf['_LOCAL_LANG.']) as $lang => $arrLang) {
                foreach ($arrLang as $langKey => $langValue) {
                    $this->LOCAL_LANG[$lang][$langKey]['0'] = $this->LOCAL_LANG['default'][$langKey]['0'];

                    $this->LOCAL_LANG[$lang][$langKey]['0']['target'] = $langValue;
                }
            }
        }

        // UserId ermitteln.
        if (null !== $this->frontendController->fe_user->user) {
            $this->userId = $this->frontendController->fe_user->user['uid'];
        }

        // ContentId ermitteln.
        $this->contentId = $this->cObj->data['uid'];

        $this->feUsersTca = $this->utils->getFeUsersTca($this->conf['fieldconfig.']);
        $userfolder = (int)$this->getConfigurationByShowtype('userfolder');

        $this->storagePageId = $this->utils->getStoragePageId($userfolder);
        $this->repository = GeneralUtility::makeInstance(\Datamints\Feuser\Domain\Repository\FeUserRepository::class, $this->storagePageId);

        // Stylesheets in den Head einbinden.
        $this->frontendController->additionalHeaderData[$this->prefixId . '[stylesheet]'] = ($this->conf['disablestylesheet']) ? '' : '<link rel="stylesheet" type="text/css" href="' . ($this->conf['stylesheetpath'] ?: $this->utils->getTypoLinkUrl(PathUtility::stripPathSitePrefix(ExtensionManagementUtility::extPath($this->extKey)) . 'res/datamints_feuser.css')) . '" />';

        // Javascripts in den Head einbinden.
        if (!$this->conf['disablejsvalidator']) {
            $collector = GeneralUtility::makeInstance(AssetCollector::class);
            assert($collector instanceof AssetCollector);
            $jsvalidatorpath = $this->conf['jsvalidatorpath'] ?: 'EXT:' . $this->extKey . '/Resources/Public/validator.js';
            $collector->addJavaScript(
                '@datamints/feuser/validator',
                $jsvalidatorpath,
            );
        }
        // $this->frontendController->additionalHeaderData[$this->prefixId . '[jsvalidator]'] = ($this->conf['disablejsvalidator']) ? '' : '<script type="text/javascript" src="' . () . '"></script>';


        $this->frontendController->additionalHeaderData[$this->prefixId . '[jsvalidation]'] = ($this->conf['disablejsconfig']) ? '' : '<script type="text/javascript">' . "\n/*<![CDATA[*/\n" . 'var datamints_feuser_config=[];var datamints_feuser_inputids=[];' . "\n/*]]>*/\n" . '</script>';
        $this->frontendController->additionalHeaderData[$this->prefixId . '[jsvalidation][' . $this->contentId . ']'] = ($this->conf['disablejsconfig']) ? '' : '<script type="text/javascript">' . "\n/*<![CDATA[*/\n" . $this->getJSValidationConfiguration() . "\n/*]]>*/\n" . '</script>';

        // Wenn nicht eingeloggt kann man auch nicht editieren!
        if ($this->conf['showtype'] == self::showtypeKeyEdit && !$this->userId) {
            return $this->pi_wrapInBaseClass($this->showOutputRedirect(self::showtypeKeyEdit, self::submodeKeyError . '_login'));
        }

        // Wenn ein "userfolder" angegeben ist, der aktuelle User aber nicht in diesem ist, kann man auch nicht editieren!
        if ($this->conf['showtype'] == self::showtypeKeyEdit && $this->getConfigurationByShowtype('userfolder') && $this->frontendController->fe_user->user['pid'] != $this->storagePageId) {
            return $this->pi_wrapInBaseClass($this->showOutputRedirect(self::showtypeKeyEdit, self::submodeKeyError . '_userfolder'));
        }

        switch ($this->piVars[$this->contentId][self::submitparameterKeyMode]) {
            case self::modeKeySend:
                $content = $this->doFormSubmit();
                break;

            case self::modeKeyApprovalcheck:
                // Userid ermitteln und Aktivierung durchfuehren.
                $this->userId = intval($this->piVars[$this->contentId]['uid']);

                $content = $this->doApprovalCheck();
                break;

            default:
                $content = $this->showForm();
                break;
        }

        return $this->pi_wrapInBaseClass($content);
    }

    /**
     * Bereitet die uebergebenen Daten fuer den Import in die Datenbank vor, und fuehrt diesen, wenn es keine Fehler gab, aus.
     */
    public function doFormSubmit(): string
    {
        $mode = $this->conf['showtype'];
        $submode = self::submodeKeyFailure;
        $params = [];
        $arrUpdate = [];

        // Falls ein Leerstring in einem Array-Wert an erster Stelle steht, handelt es sich um ein verstecktes Feld. Dieses muss entfernt werden.
        $this->utils->shiftEmptyArrayValuePostArray($this->piVars[$this->contentId]);

        // Jedes Element in piVars trimmen.
        array_walk_recursive($this->piVars[$this->contentId], function (&$item) {
            $this->utils->trimCallback($item);
        });

        // Eine Validierung durchfuehren ueber alle Felder die eine gesonderte Konfigurtion bekommen haben.
        $validCheck = $this->checkValid();

        // Ueberpruefen ob Datenbankeintraege mit den uebergebenen Daten uebereinstimmen.
        $uniqueCheck = $this->checkUnique();

        // Ueberpruefen ob in allen benoetigten Feldern etwas drinn steht.
        $requiredCheck = $this->checkRequired();

        // Wenn bei der Validierung ein Feld nicht den Anforderungen entspricht noch einmal die Form anzeigen und entsprechende Felder markieren.
        $valueCheck = array_merge($validCheck, $uniqueCheck, $requiredCheck);

        if (count($valueCheck) > 0) {
            return $this->showForm($valueCheck);
        }

        // Wenn der User eine neue Aktivierungsmail beantragt hat.
        if ($this->piVars[$this->contentId][self::specialfieldKeyResendactivation] && in_array($this->utils->getSpecialFieldKey(self::specialfieldKeyResendactivation), $this->arrUsedFields)) {
            // Falls der Anzeigetyp "list" ist (Liste der im Cookie gespeicherten User), alle uebergebenen User ermitteln und fuer das erneute zusenden verwenden. Ansonsten die uebergebene E-Mail verwenden.
            //          if ($this->conf['shownotactivated'] == 'list') {
            //              $arrNotActivated = $this->getNotActivatedUserArray($this->piVars[$this->contentId][$fieldName]);
            //              $res = $this->databaseConnection->exec_SELECTquery('uid, tx_datamintsfeuser_approval_level', 'fe_users', 'pid = ' . $this->storagePageId . ' AND uid IN(' . implode(',', $arrNotActivated) . ') AND disable = 1 AND deleted = 0');
            //          } else {
            //$res = $this->databaseConnection->exec_SELECTquery('uid, tx_datamintsfeuser_approval_level', 'fe_users', 'pid = ' . $this->storagePageId . ' AND email = ' . $this->databaseConnection->fullQuoteStr(strtolower((string)$this->piVars[$this->contentId][self::specialfieldKeyResendactivation]), 'fe_users') . ' AND disable = 1 AND deleted = 0', '', '', '1');
            //          }
            $rows = $this->repository->findOneByEmail(strtolower((string)$this->piVars[$this->contentId][self::specialfieldKeyResendactivation]));
            //while ($row = $this->databaseConnection->sql_fetch_assoc($res)) {
            foreach ($rows as $row) {
                // Genehmigungstypen aufsteigend sortiert ermitteln. Das ist noetig um das Level dem richtigen Typ zuordnen zu koennen.
                // Beispiel: approvalcheck = ,doubleoptin,adminapproval => beim exploden kommt dann ein leeres Arrayelement herraus, das nach dem entfernen einen leeren Platz uebrig laesst.
                $arrApprovalTypes = $this->getApprovalTypes();
                $approvalType = $arrApprovalTypes[count($arrApprovalTypes) - $row['tx_datamintsfeuser_approval_level']];

                // Ausgabe vorbereiten.
                $mode = self::modeKeyResendactivation;

                if ($approvalType) {
                    if ($this->isAdminApprovalType($approvalType)) {
                        // Fehler anzeigen, falls das naechste Genehmigungsverfahren den Admin betrifft.
                        $submode = self::submodeKeyError . '_' . $approvalType;
                    } else {
                        // Aktivierungsmail senden und Ausgabe anpassen.
                        $submode = self::submodeKeySent;

                        $this->sendActivationMail($row['uid']);
                    }
                }
            }

            return $this->showOutputRedirect($mode, $submode);
        }

        // Wenn die Zielseite, der User oder der Bearbeitungsmodus nicht stimmen, dann wird abgebrochen. Andernfalls wird in die Datenbank geschrieben.
        if ($this->piVars[$this->contentId][self::submitparameterKeyPage] != $this->frontendController->id || $this->piVars[$this->contentId][self::submitparameterKeyUser] != $this->userId || $this->piVars[$this->contentId][self::submitparameterKeySubmode] != $this->conf['showtype']) {
            return $this->showOutputRedirect($mode, $submode);
        }

        // Sonderfaelle behandeln!
        foreach ($this->arrUsedFields as $fieldName) {
            if (!is_array($this->feUsersTca['columns'][$fieldName])) {
                continue;
            }

            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            $arrFieldConfigEval = GeneralUtility::trimExplode(',', $fieldConfig['eval'], true);

            // Ist das Feld schon gesaeubert worden (MySQL, PHP, HTML, ...).
            $isCleaned = false;

            // Datumsfelder behandeln.
            if (in_array('date', $arrFieldConfigEval)) {
                $date = date_create_from_format($this->conf['format.']['date'], $this->piVars[$this->contentId][$fieldName]);

                if ($date) {
                    $arrUpdate[$fieldName] = date_timestamp_get($date);
                }

                $isCleaned = true;
            }

            // Datumzeitfelder behandeln.
            if (in_array('datetime', $arrFieldConfigEval)) {
                $datetime = date_create_from_format($this->conf['format.']['datetime'], $this->piVars[$this->contentId][$fieldName]);

                if ($datetime) {
                    $arrUpdate[$fieldName] = date_timestamp_get($datetime);
                }

                $isCleaned = true;
            }

            // Passwordfelder behandeln.
            if (in_array('password', $arrFieldConfigEval)) {
                $this->cleanPasswordField($arrUpdate, $fieldName, $fieldConfig);

                $isCleaned = true;
            }

            // Read only behandeln.
            if ($fieldConfig['readOnly']) {
                $isCleaned = true;
            }

            // Checkboxen behandeln.
            if ($fieldConfig['type'] == 'check') {
                $this->cleanCheckField($arrUpdate, $fieldName, $fieldConfig);

                $isCleaned = true;
            }

            // Multiple Checkboxen / Selectboxen.
            if ($fieldConfig['type'] == 'select' && $fieldConfig['size'] > 1) {
                $this->cleanMultipleSelectField($arrUpdate, $fieldName, $fieldConfig);
                $isCleaned = true;
            }

            // Dateifelder behandeln.
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'file') {
                $arrUpdate[$fieldName] = $this->frontendController->fe_user->user[$fieldName];

                // Das Bild hochladen oder loeschen. Gibt einen Fehlerstring per Referenz zurueck falls ein Fehler auftritt!
                $valueCheck[$fieldName] = $this->saveDeleteFiles($arrUpdate, $fieldName, $fieldConfig);

                if ($valueCheck[$fieldName]) {
                    return $this->showForm($valueCheck);
                }

                $isCleaned = true;
            }

            // Datenbank-Gruppenfelder.
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'db') {
                $this->cleanGroupDatabaseField($arrUpdate, $fieldName, $fieldConfig);
                $isCleaned = true;
            }

            // Wenn noch nicht gesaeubert dann nachholen!
            if (!$isCleaned && isset($this->piVars[$this->contentId][$fieldName])) {
                $this->cleanUncleanedField($arrUpdate, $fieldName, $fieldConfig);
            }
        }

        // Konvertiert alle moeglichen Zeichen die fuer die Ausgabe angepasst wurden zurueck.
        $this->utils->htmlspecialcharsPostArray($arrUpdate, true);

        // Zusatzfelder setzten, die nicht aus der Form uebergeben wurden.
        $arrUpdate['tstamp'] = time();

        // Wenn der User geloescht werden soll.
        if ($this->piVars[$this->contentId][self::specialfieldKeyUserdelete] && in_array($this->utils->getSpecialFieldKey(self::specialfieldKeyUserdelete), $this->arrUsedFields)) {
            $arrUpdate['deleted'] = '1';
        }

        // Kopiert den Inhalt eines Feldes in ein anderes Feld.
        $this->copyFields($arrUpdate);

        // Der User hat seine Daten editiert.
        if ($this->conf['showtype'] == self::showtypeKeyEdit) {
            $arrMode = $this->doUserEdit($arrUpdate);

            // Ausgabe vorbereiten.
            $mode = $arrMode['mode'];
            $submode = $arrMode['submode'];
            $params = $arrMode['params'] ?? $params;
        }

        // Ein neuer User hat sich angemeldet.
        if ($this->conf['showtype'] == self::showtypeKeyRegister) {
            $arrMode = $this->doUserRegister($arrUpdate);

            // Ausgabe vorbereiten.
            $mode = $arrMode['mode'];
            $submode = $arrMode['submode'];
            $params = $arrMode['params'] ?? $params;
        }

        // Hook um weiter Userupdates zu machen.
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['sendForm'])) {
            $_params = ['variables' => ['arrUpdate' => $arrUpdate], 'parameters' => ['mode' => &$mode, 'submode' => &$submode, 'params' => &$params]];

            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['sendForm'] as $_funcRef) {
                GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }

        return $this->showOutputRedirect($mode, $submode, $params);
    }

    /**
     * Ueberprueft ob alle Validierungen eingehalten wurden.
     *
     * @return  array       $valueCheck
     * @throws \Doctrine\DBAL\Exception
     */
    public function checkValid(): array
    {
        $valueCheck = [];

        // Alle ausgewaehlten Felder durchgehen.
        foreach ($this->arrUsedFields as $fieldName) {
            $fieldName = $this->utils->getSpecialFieldName($fieldName);
            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];

            $value = $this->piVars[$this->contentId][$fieldName] ?? null;
            $validate = $this->conf['validate.'][$fieldName . '.'];

            // Besonderes Feld das fest in der Extension verbaut ist (passwordconfirmation), und ueberprueft werden soll.
            if ($fieldName == self::specialfieldKeyPasswordconfirmation && $this->conf['showtype'] == self::showtypeKeyEdit && !$this->isPasswordSameAsCurrentFeUserPassword($value)) {
                $valueCheck[$fieldName] = self::validationerrorKeyValid;
            }

            // Besonderes Feld das fest in der Extension verbaut ist (resendactivation), und ueberprueft werden soll.
            if ($fieldName == self::specialfieldKeyResendactivation && $value) {
                $count = $this->repository->countByUidOrEmail($value);

                if ($count < 1) {
                    $valueCheck[$fieldName] = self::validationerrorKeyValid;
                }
            }

            // Besonderes Feld das fest in der Extension verbaut ist (captcha), und ueberprueft werden soll.
            // Fuer "jm_recaptcha" darf hier $value nicht ueberprueft werden, wurde aber vorerst entfernt, da das an mehreren Stellen beruecksichtigt werden muesste!
            if ($fieldName == self::specialfieldKeyCaptcha && $value !== null && $value !== '') {
                $captchaCheck = $this->checkCaptcha($value);

                if ($captchaCheck) {
                    $valueCheck[$fieldName] = $captchaCheck;
                }
            }

            // Wenn der im TypoScript angegebene Feldname nicht im TCA ist, dann naechstes Feld vornehmen.
            if (!is_array($this->feUsersTca['columns'][$fieldName])) {
                continue;
            }

            // Wenn das Feld ueberhaupt nicht angezeigt wurde, dann naechstes Feld vornehmen.
            if (!in_array($fieldName, $this->arrUsedFields)) {
                continue;
            }

            // Wenn ein Modus fuer dieses Feld konfiguriert wurde, und der Konfigurierte Modus nicht mit dem Anzeigetyp uebereinstimmt, dann naechstes Feld vornehmen.
            if ($validate['mode'] && $validate['mode'] != $this->conf['showtype']) {
                continue;
            }

            // Wenn ueberhaupt kein Wert / Parameter uebergeben wurde, dann naechstes Feld vornehmen.
            if (null === $value) {
                continue;
            }

            // Wenn kein Inhalt im Parameter steht und wenn der Typ des Feldes nicht check, radio oder select ist, dann naechstes Feld vornehmen.
            if (!$value && !in_array($fieldConfig['type'], ['check', 'radio', 'select'])) {
                continue;
            }

            // Ansonsten Feldvalidierung anhand des Validierungstyps vornehmen.
            switch ($validate['type']) {
                case 'password':
                    $valueRep = $this->piVars[$this->contentId][$fieldName . '_rep'];
                    $arrLength[0] = '6';

                    if ($value == $valueRep) {
                        if ($validate['length']) {
                            $arrLength = GeneralUtility::trimExplode(',', $validate['length']);
                        }

                        if (!preg_match('/^.{' . $arrLength[0] . ',' . $arrLength[1] . '}$/', (string)$value)) {
                            $valueCheck[$fieldName] = self::validationerrorKeyLength;
                        }
                    } else {
                        $valueCheck[$fieldName] = self::validationerrorKeyEqual;
                    }

                    break;

                case 'email':
                    if (!preg_match('/^[a-zA-Z0-9\._%+-]+@[a-zA-Z0-9\.-]+\.[a-zA-Z]{2,63}$/', (string)$value)) {
                        $valueCheck[$fieldName] = self::validationerrorKeyValid;
                    }

                    break;

                case 'username':
                    if (!preg_match('/^[^ ]*$/', (string)$value)) {
                        $valueCheck[$fieldName] = self::validationerrorKeyValid;
                    }

                    break;

                case 'zero':
                    if ($value == '0') {
                        $valueCheck[$fieldName] = self::validationerrorKeyValid;
                    }

                    break;

                case 'emptystring':
                    if ($value == '') {
                        $valueCheck[$fieldName] = self::validationerrorKeyValid;
                    }

                    break;

                case 'custom':
                    if ($validate['regexp']) {
                        if (is_array($value)) {
                            foreach ($value as $subValue) {
                                if (!preg_match($validate['regexp'], (string)$subValue)) {
                                    $valueCheck[$fieldName] = self::validationerrorKeyValid;
                                }
                            }
                        } elseif (!preg_match($validate['regexp'], (string)$value)) {
                            $valueCheck[$fieldName] = self::validationerrorKeyValid;
                        }
                    }

                    if ($validate['length']) {
                        $arrLength = GeneralUtility::trimExplode(',', $validate['length']);

                        if (is_array($value)) {
                            if (($arrLength[0] && count($value) < $arrLength[0]) || ($arrLength[1] && count($value) > $arrLength[1])) {
                                $valueCheck[$fieldName] = self::validationerrorKeyLength;
                            }
                        } elseif (!preg_match('/^.{' . $arrLength[0] . ',' . $arrLength[1] . '}$/', (string)$value)) {
                            $valueCheck[$fieldName] = self::validationerrorKeyLength;
                        }
                    }

                    break;
            }
        }

        return $valueCheck;
    }

    /**
     * Ueberprueft die uebergebenen Inhalte, bei bestimmten Feldern, ob diese in der Datenbank schon vorhanden sind.
     *
     * @return  array       $valueCheck
     * @throws \Doctrine\DBAL\Exception
     */
    public function checkUnique(): array
    {
        $valueCheck = [];

        foreach ($this->arrUniqueFields as $fieldName) {
            if ($this->piVars[$this->contentId][$fieldName]) {
                //$res = $this->databaseConnection->exec_SELECTquery('COUNT(uid) as count', 'fe_users', $fieldName . ' = ' . $this->databaseConnection->fullQuoteStr($this->piVars[$this->contentId][$fieldName], 'fe_users') . $where . ' AND deleted = 0');
                //$row = $this->databaseConnection->sql_fetch_assoc($res);

                $count = $this->repository->countByFieldAndValue(
                    $fieldName,
                    $this->piVars[$this->contentId][$fieldName],
                    $this->userId ?? 0,
                    $this->conf['showtype'] == self::showtypeKeyEdit,
                    !$this->conf['uniqueglobal'] && $this->getConfigurationByShowtype('userfolder')
                );

                if ($count >= 1) {
                    $valueCheck[$fieldName] = self::validationerrorKeyUnique;
                }
            }
        }

        return $valueCheck;
    }

    /**
     * Ueberprueft ob alle benoetigten Felder mit Inhalten uebergeben wurden.
     *
     * @return  array       $valueCheck
     */
    public function checkRequired(): array
    {
        $valueCheck = [];

        // Geht alle benoetigten Felder durch und ermittelt fehlende.
        foreach ($this->arrRequiredFields as $fieldName) {
            // Ueberpruefen, ob das Feld ueberhaupt angezeigt wurde.
            if (!in_array($fieldName, $this->arrUsedFields)) {
                continue;
            }

            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];

            $fieldName = $this->utils->getSpecialFieldName($fieldName);
            $fieldValue = $this->piVars[$this->contentId][$fieldName];

            // Arrays zum Ueberpruefen normalisieren, und leere Werte entfernen!
            if (is_array($fieldValue)) {
                $fieldValue = implode(',', GeneralUtility::trimExplode(',', implode(',', $fieldValue), true));
            }

            // Dadurch dass die einfache Checkbox ein besonderes verstecktes Feld hat (value="0"), muss dieser Wert erst normalisiert werden!
            if ($fieldConfig['type'] == 'check' && $fieldValue == '0') {
                $fieldValue = '';
            }

            $valueCheck[$fieldName] = self::validationerrorKeyRequired;

            // Fuer Felder vom Typ group (internal_type="file") wird eine Ueberpruefung auf eine vorhandene Datei gemacht.
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'file') {
                if (!is_array($this->piVars[$this->contentId][$fieldName])) {
                    continue;
                }

                $arrFieldVars = $this->piVars[$this->contentId][$fieldName];
                $arrFilenames = GeneralUtility::trimExplode(',', $this->frontendController->fe_user->user[$fieldName], true);

                foreach ($arrFieldVars['files'] as $sentKey => $filename) {
                    $sentKey = intval($sentKey);
                    $savedKey = array_search($filename, $arrFilenames, true);

                    // Wenn eine Datei vorhanden (egal ob neu uebergeben oder bereits vorhanden) und diese nicht geloescht wird, wird kein Fehler zurueckgegeben!
                    if ($_FILES[$this->prefixId]['name'][$this->contentId][$fieldName]['upload'][$sentKey] || ($savedKey !== false && !$arrFieldVars['delete'][$sentKey])) {
                        unset($valueCheck[$fieldName]);
                    }
                }

                continue;
            }

            // Durch die versteckten Felder wird immer ein Wert uebergeben, dadurch muss nur ueberprueft werden, ob der Inhalt ungleich einem Leerstring ist!
            if ($fieldValue != '') {
                unset($valueCheck[$fieldName]);
            }
        }

        return $valueCheck;
    }

    /**
     * Ueberprueft ob das Captcha richtig eingegeben wurde.
     *
     * @param string $value
     */
    public function checkCaptcha($value): string
    {
        if (!ExtensionManagementUtility::isLoaded($this->conf['captcha.']['use'])) {
            return '';
        }

        if ($this->conf['captcha.']['use'] === 'powermail') {
            $calculatingCaptchaService = GeneralUtility::makeInstance(CalculatingCaptchaService::class);
            $field = new Field();
            $field->_setProperty('uid', $this->contentId);
            if (!$calculatingCaptchaService->validCode($value, $field)) {
                return self::validationerrorKeyValid;
            }
        }

        return '';
    }

    /**
     * Falls angegebe das Passwort fuer ein Passwortfeld generieren und / oder verschluesseln.
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     * @param array $fieldConfig
     */
    public function cleanPasswordField(array &$arrUpdate, $fieldName, $fieldConfig): bool
    {
        // Password generieren und verschluesseln je nach Einstellung.
        $password = $this->utils->generatePassword($this->piVars[$this->contentId][$fieldName], $this->getConfigurationByShowtype('generatepassword.'));
        $arrUpdate[$fieldName] = $password['encrypted'];

        // Wenn kein Password uebergeben wurde auch keins schreiben.
        if (!$arrUpdate[$fieldName]) {
            unset($arrUpdate[$fieldName]);
        }

        return true;
    }

    /**
     * Saeubert Checkboxfelder, indem die uebergebenen Werte durch 1 oder 0 ausgetauscht werden.
     * Gilt fuer eine oder mehrere Checkboxen (nicht fuer scrollbare Listen).
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     */
    public function cleanCheckField(array &$arrUpdate, $fieldName, array $fieldConfig): bool
    {
        $checkItemsCount = count((array)$fieldConfig['items']);

        // Mehrere Checkboxen oder eine Checkbox.
        if ($checkItemsCount > 1) {
            $binString = '';
            for ($key = 0; $key < $checkItemsCount; $key++) {
                if ($this->piVars[$this->contentId][$fieldName][$key]) {
                    $binString .= '1';
                } else {
                    $binString .= '0';
                }
            }

            $arrUpdate[$fieldName] = bindec(strrev($binString));
        } elseif ($this->piVars[$this->contentId][$fieldName]) {
            $arrUpdate[$fieldName] = '1';
        } else {
            $arrUpdate[$fieldName] = '0';
        }

        return true;
    }

    /**
     * Saeubert MultipleSelectboxfelder indem auf jeden uebergebenen Wert intval() angewendet wird.
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     */
    public function cleanMultipleSelectField(array &$arrUpdate, $fieldName, array $fieldConfig): bool
    {
        $maxItemsCount = 1;
        $arrCleanedValues = [];

        // Wenn nichts ausgewaehlt wurde, wird dieser Parameter auch nicht uebergeben, daher zuerst ueberpruefen, ob etwas vorhanden ist.
        if (!is_array($this->piVars[$this->contentId][$fieldName])) {
            return false;
        }

        foreach ($this->piVars[$this->contentId][$fieldName] as $val) {
            // Einen leeren String als Uebergabewert gibt es nicht, bzw. das ist das versteckte Feld, um alle Werte abwaehlen zu koennen!
            if ($val == '') {
                continue;
            }

            if ($fieldConfig['maxitems'] && $maxItemsCount > $fieldConfig['maxitems']) {
                break;
            }

            $arrCleanedValues[] = intval($val);
            $maxItemsCount++;
        }

        $arrUpdate[$fieldName] = implode(',', $arrCleanedValues);

        return true;
    }

    /**
     * Saeubert Group- und MultipleCheckboxfelder (scrollbare Liste).
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     */
    public function cleanGroupDatabaseField(array &$arrUpdate, $fieldName, array $fieldConfig): bool
    {
        $maxItemsCount = 1;
        $arrCleanedValues = [];

        $arrAllowed = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);

        // Hier werden absichtlich nur die Erlaubten Tabellen benutzt, da es sonst unmengen an möglichen Optionen geben wuerde!
        foreach ($arrAllowed as $table) {
            if (!$GLOBALS['TCA'][$table]) {
                continue;
            }

            if (!is_array($this->piVars[$this->contentId][$fieldName])) {
                continue;
            }

            foreach ($this->piVars[$this->contentId][$fieldName] as $val) {
                if ($fieldConfig['maxitems'] && $maxItemsCount > $fieldConfig['maxitems']) {
                    break;
                }

                if (preg_match('/^' . $table . '_[0-9]+$/', (string)$val)) {
                    $arrCleanedValues[] = $val;
                    $maxItemsCount++;
                }
            }
        }

        // Falls nur eine Tabelle im TCA angegeben ist, wird nur die uid gespeichert.
        // ToDo: TCA Option "prepend_tname" beachten!
        if (count($arrAllowed) == 1) {
            foreach ($arrCleanedValues as $key => $val) {
                $arrCleanedValues[$key] = substr((string)$val, strripos((string)$val, '_') + 1);
            }
        }

        $arrUpdate[$fieldName] = implode(',', $arrCleanedValues);

        return true;
    }

    /**
     * Saeubert die uebrigen Felder (Input, Textarea, ...).
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     */
    public function cleanUncleanedField(array &$arrUpdate, $fieldName, array $fieldConfig): bool
    {
        // Wenn eine Selectbox die Ihren Inhalt aus einer anderen Tabelle hat angezeigt wurde, dann darf nur eine Zahl kommen!
        if ($fieldConfig['type'] == 'select' && $fieldConfig['foreign_table']) {
            $arrUpdate[$fieldName] = intval($this->piVars[$this->contentId][$fieldName]);

            return true;
        }

        // Ansonsten Standardsaeuberung.
        $arrUpdate[$fieldName] = strip_tags((string)$this->piVars[$this->contentId][$fieldName]);

        // Wenn E-Mail Feld, alle Zeichen zu kleinen Zeichen konvertieren.
        if ($fieldName == 'email') {
            $arrUpdate[$fieldName] = strtolower($arrUpdate[$fieldName]);
        }

        return true;
    }

    /**
     * The saveDeleteImage method is used to update or delete an image of an address
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem das zu saubernde Feld ist.
     * @param string $fieldName
     * @return  string      $error
     */
    public function saveDeleteFiles(array &$arrUpdate, $fieldName, array $fieldConfig): string
    {
        $error = '';

        if (!is_array($this->piVars[$this->contentId][$fieldName])) {
            return $error;
        }

        $arrFieldVars = $this->piVars[$this->contentId][$fieldName];

        $maxSize = $fieldConfig['max_size'] * 1024;
        $uploadFolder = $this->utils->fixPath($fieldConfig['uploadfolder']);
        $allowedTypes = GeneralUtility::trimExplode(',', strtolower(str_replace('*', '', $fieldConfig['allowed'])), true);
        $disallowedTypes = GeneralUtility::trimExplode(',', strtolower(str_replace('*', '', $fieldConfig['disallowed'])), true);

        $arrFilenames = GeneralUtility::trimExplode(',', $arrUpdate[$fieldName], true);

        $arrProcessedKeys = [];

        foreach ($arrFieldVars['files'] as $sentKey => $filename) {
            $sentKey = intval($sentKey);
            $savedKey = array_search($filename, $arrFilenames, true);
            // Falls schon abgearbeitet oder die maximale Anzahl erricht ist, abbrechen!
            if (in_array($sentKey, $arrProcessedKeys)) {
                continue;
            }

            if ($fieldConfig['maxitems'] && count($arrProcessedKeys) >= $fieldConfig['maxitems']) {
                continue;
            }

            // Wenn kein Bild hochgeladen wurde und keines geloescht werden kann, abbrechen!
            if (!$_FILES[$this->prefixId]['name'][$this->contentId][$fieldName]['upload'][$sentKey] && !($savedKey !== false && $arrFieldVars['delete'][$sentKey])) {
                continue;
            }

            // Fehlermeldung vorbereiten.
            $error = self::validationerrorKeyUpload;
            $arrProcessedKeys[] = $sentKey;

            // Falls das Bild durch den User geloescht wird, soll bei einem Fehler auch eine entsprechende Fehlermeldung angezeigt werden!
            if ($arrFieldVars['delete'][$sentKey]) {
                $error = self::validationerrorKeyDelete;
            }

            $newFilename = '';

            if ($_FILES[$this->prefixId]['name'][$this->contentId][$fieldName]['upload'][$sentKey]) {
                // Die konfigurierte maximale Dateigroesse wirde ueberschritten.
                if ($maxSize && $_FILES[$this->prefixId]['size'][$this->contentId][$fieldName]['upload'][$sentKey] > $maxSize) {
                    $error = self::validationerrorKeySize;

                    break;
                }

                // Der Upload war nicht vollstaendig (Datei zu gross => Zeitueberschreitung).
                if ($_FILES[$this->prefixId]['error'][$this->contentId][$fieldName]['upload'][$sentKey] == '2') {
                    $error = self::validationerrorKeySize;

                    break;
                }

                $newFiletype = pathinfo(strtolower((string)$_FILES[$this->prefixId]['name'][$this->contentId][$fieldName]['upload'][$sentKey]), PATHINFO_EXTENSION);

                // Wenn nur bestimmte Datei-Typen erlaubt sind, und der aktuelle Typ nicht in den Erlaubten enthalten ist!
                if ($allowedTypes && !in_array($newFiletype, $allowedTypes)) {
                    $error = self::validationerrorKeyType;

                    break;
                }

                // Wenn bestimmte Datei-Typen nicht erlaubt sind, und der aktuelle Typ in den Unerlaubten enthalten ist!
                if ($disallowedTypes && in_array($newFiletype, $disallowedTypes)) {
                    $error = self::validationerrorKeyType;

                    break;
                }

                $newFilename = basename(strtolower((string)$_FILES[$this->prefixId]['name'][$this->contentId][$fieldName]['upload'][$sentKey]), '.' . $newFiletype);
                $newFilename = preg_replace('/[^a-z0-9]/', '', $newFilename) . '_' . sprintf('%02d', $sentKey + 1) . '_' . time() . '.' . $newFiletype;

                $filePath = GeneralUtility::getFileAbsFileName($uploadFolder . $newFilename);

                // Bild verschieben, und anschliessend den neuen Bildnamen in die Datenbank schreiben.
                if (move_uploaded_file($_FILES[$this->prefixId]['tmp_name'][$this->contentId][$fieldName]['upload'][$sentKey], $filePath)) {
                    chmod($filePath, 0644);

                    $arrFilenames[] = $newFilename;
                    $arrFieldVars['delete'][$sentKey] = true;

                    // Wenn Das Bild erfolgreich hochgeladen wurde, Fehlermeldung zuruecksetzten.
                    $error = '';
                }
            }

            if ($savedKey !== false && $arrFieldVars['delete'][$sentKey]) {
                $filePath = GeneralUtility::getFileAbsFileName($uploadFolder . $arrFilenames[$savedKey]);

                if (file_exists($filePath) && unlink($filePath)) {
                    unset($arrFilenames[$savedKey]);

                    // Wenn Das Bild erfolgreich geloescht wurde, Fehlermeldung zuruecksetzten.
                    $error = '';
                }
            }

            if ($error) {
                break;
            }
        }

        $arrUpdate[$fieldName] = implode(',', $arrFilenames);

        return $error;
    }

    /**
     * Kopiert anhand der angegebenen Konfigurationen Inhalte in dem uebergebenen Array an eine neue oder andere Stelle.
     * Dabei wird auf jeden kopierten Inhalt die stdWrap Funktionen angewendet.
     *
     * @param array $arrUpdate // Call by reference: Das Array dessen Inhalte kopiert werden.
     */
    public function copyFields(array &$arrUpdate): bool
    {
        if (!is_array($this->conf['copyfields.'])) {
            return false;
        }

        // Kopiert den Inhalt eines Feldes in ein anderes Feld.
        $arrCopiedFields = [];

        foreach ($this->conf['copyfields.'] as $fieldToCopy => $arrCopyToFields) {
            $fieldToCopy = rtrim($fieldToCopy, '.');

            // Wenn das Feld nich existiert, ueberspringen.
            if (!array_key_exists($fieldToCopy, $this->feUsersTca['columns'])) {
                continue;
            }

            foreach (array_keys($arrCopyToFields) as $copyToField) {
                $copyToField = rtrim($copyToField, '.');

                // Wenn das Feld nich existiert, ueberspringen.
                if (!array_key_exists($copyToField, $this->feUsersTca['columns'])) {
                    continue;
                }

                // Wenn in das Feld bereits kopiert wurde, ueberspringen.
                if (in_array($copyToField, $arrCopiedFields)) {
                    continue;
                }

                // Wenn das Feld den Modus "onlyused" hat und nicht im Formular angezeigt wurde, ueberspringen.
                if ($arrCopyToFields[$copyToField] == 'onlyused' && !in_array($fieldToCopy, $this->arrUsedFields)) {
                    continue;
                }

                // Wenn aktiviert, stdWrap anwenden.
                if ($arrCopyToFields[$copyToField]) {
                    $arrCopiedFields[] = $copyToField;

                    $arrUpdate[$copyToField] = $this->utils->currentUserWrap($arrUpdate[$fieldToCopy], $arrCopyToFields[$copyToField . '.']);
                }
            }
        }

        return true;
    }

	/**
	 * Aktualisiert einen vorhandenen User, anhand des uebergebenen Arrays.
	 *
	 * @param array $arrUpdate // Call by reference: Das Array mit den bearbeiteten Userdaten.
	 * @return  array       $arrMode
	 * @throws InvalidPasswordHashException
	 */
    public function doUserEdit(array &$arrUpdate): array
    {
        $arrMode = [];

        if ($arrUpdate['deleted']) {
            $this->deleteUser();

            // Ausgabe vorbereiten.
            $arrMode['mode'] = $this->conf['showtype'];
            $arrMode['submode'] = self::submodeKeyUserdelete;
            $arrMode['params'] = ['refresh' => GeneralUtility::getIndpEnv('TYPO3_SITE_URL')];

            return $arrMode;
        }

        // ToDo: MM-Relation, MM-Relationen setzen und die Anzahl der Relationen aktualisieren.
        $this->insertRelationInserts($this->userId, $this->getRelationInserts($arrUpdate));

        // Der User hat seine Daten editiert.
			$arrUpdate = $this->hashPasswordInInputValues($arrUpdate);
			$this->repository->update($this->userId, $arrUpdate);
        // Destroy sessions if a password field was submitted!
        if (method_exists(SessionManager::class, 'invalidateAllSessionsByUserId')) {
            $passwordFields = array_filter(array_map(fn(array $column): bool => in_array('password', GeneralUtility::trimExplode(',', $column['config']['eval'], true)), $this->feUsersTca['columns']));

            if (count(array_intersect_key($arrUpdate, $passwordFields)) > 0) {
                $sessionManager = GeneralUtility::makeInstance(SessionManager::class);
                $sessionManager->invalidateAllSessionsByUserId($sessionManager->getSessionBackend('FE'), $this->userId, $this->frontendController->fe_user);
            }
        }

        // User und Admin Benachrichtigung schicken, aber nur wenn etwas geaendert wurde.
        if ($this->getConfigurationByShowtype('sendusermail') || $this->getConfigurationByShowtype('sendadminmail')) {
            $extraMarkers = $this->getChangedForMail($arrUpdate, $this->getConfigurationByShowtype());

            if ($this->getConfigurationByShowtype('sendadminmail') && ($this->getConfigurationByShowtype('sendadminmail') != 'onlychanged' || isset($extraMarkers['nothing_changed']))) {
                $this->sendMail($this->userId, self::showtypeKeyEdit, true, $this->getConfigurationByShowtype(), $extraMarkers);
            }

            if ($this->getConfigurationByShowtype('sendusermail') && ($this->getConfigurationByShowtype('sendusermail') != 'onlychanged' || isset($extraMarkers['nothing_changed']))) {
                // ToDo: Hier vielleicht noch mit Passwort-Generierung?
                $this->sendMail($this->userId, self::showtypeKeyEdit, false, $this->getConfigurationByShowtype(), $extraMarkers);
            }
        }

        // Ausgabe vorbereiten.
        $arrMode['mode'] = $this->conf['showtype'];
        $arrMode['submode'] = self::submodeKeySuccess;
        $arrMode['params'] = ['refresh' => GeneralUtility::locationHeaderUrl($this->utils->getTypoLinkUrl($this->frontendController->id))];

        return $arrMode;
    }

    /**
     * Loescht einen vorhandenen User.
     */
    public function deleteUser(): void
    {
        if ($this->getConfigurationByShowtype('userdelete')) {
            // Den User endgueltig loeschen.
            //$this->databaseConnection->exec_DELETEquery('fe_users', 'uid = ' . $this->userId);
            $this->repository->delete($this->userId);
        } else {
            // Den User als geloescht markieren.
            //$this->databaseConnection->exec_UPDATEquery('fe_users', 'uid = ' . $this->userId, ['tstamp' => time(), 'deleted' => '1']);
            $this->repository->update($this->userId, ['tstamp' => time(), 'deleted' => 1]);
        }
    }

    /**
     * Erstellt einen User, anhand des uebergebenen Arrays.
     *
     * @param array $arrUpdate // Call by reference: Das Array mit den neuen Userdaten.
     * @return  array       $arrMode
     * @throws InvalidPasswordHashException
     */
    public function doUserRegister(array &$arrUpdate): array
    {
        $arrMode = [];

        // Standard-Konfigurationen anwenden.
        $arrUpdate['pid'] = $this->storagePageId;
        $arrUpdate['crdate'] = $arrUpdate['tstamp'];
        $arrUpdate['usergroup'] = $arrUpdate['usergroup'] ?: $this->getConfigurationByShowtype('usergroup');

        // Genehmigungstypen aufsteigend sortiert ermitteln. Das ist noetig um das Level dem richtigen Typ zuordnen zu koennen.
        // Beispiel: approvalcheck = ,doubleoptin,adminapproval => beim exploden kommt dann ein leeres Arrayelement herraus, das nach dem entfernen einen leeren Platz uebrig laesst.
        $arrApprovalTypes = $this->getApprovalTypes();

        // Maximales Genehmigungslevel ermitteln (Double Opt In / Admin Approval).
        $arrUpdate['tx_datamintsfeuser_approval_level'] = count($arrApprovalTypes);

        // Wenn ein Genehmigungstyp aktiviert ist, dann den User deaktivieren.
        if ($arrUpdate['tx_datamintsfeuser_approval_level'] > 0) {
            $arrUpdate['disable'] = '1';
        }

        // ToDo: MM-Relation, MM-Relationen ermitteln und die Anzahl der Relationen aktualisieren.
        $arrRelationInserts = $this->getRelationInserts($arrUpdate);

        $arrUpdate = $this->hashPasswordInInputValues($arrUpdate);

        // Benutzer erstellen.
        $this->userId = $this->repository->insert($arrUpdate);


        // ToDo: MM-Relation, Ermittelte MM-Relationen setzen.
        $this->insertRelationInserts($this->userId, $arrRelationInserts);

        // Wenn nach der Registrierung weitergeleitet werden soll.
        if ($arrUpdate['tx_datamintsfeuser_approval_level'] > 0) {
            // Aktivierungsmail senden.
            $this->sendActivationMail();

            // Ausgabe fuer gemischte Genehmigungstypen erstellen (z.B. erst adminapproval und dann doubleoptin).
            $arrMode['mode'] = array_shift($arrApprovalTypes);
            $arrMode['submode'] = ((count($arrApprovalTypes) > 0) ? implode('_', $arrApprovalTypes) . '_' : '') . self::submodeKeySent;
        } else {
            // Registrierungs E-Mail schicken.
            if ($this->getConfigurationByShowtype('sendadminmail')) {
                $this->sendMail($this->userId, 'registration', true, $this->getConfigurationByShowtype());
            }

            if ($this->getConfigurationByShowtype('sendusermail')) {
                // Erstellt ein neues Passwort, falls Passwort generieren eingestellt ist. Das Passwort kannn dann ueber den Marker "###PASSWORD###" mit der Registrierungsmail gesendet werden.
                $extraMarkers = $this->getPasswordForMail();

                $this->sendMail($this->userId, 'registration', false, $this->getConfigurationByShowtype(), $extraMarkers);
            }

            $arrMode['mode'] = $this->conf['showtype'];
            $arrMode['submode'] = self::submodeKeySuccess;
            $arrMode['params'] = ['autologin' => $this->getConfigurationByShowtype('autologin')];
        }

        return $arrMode;
    }

    /**
     * Ermittelt die neuen Relationen anhand des uebergebenen Arrays.
     *
     * @param array $arrUpdate // Call by reference: Das Array in dem die Anzahl der Relationen aktualisiert werden.
     * @return  array       $arrInserts
     */
    public function getRelationInserts(array &$arrUpdate): array
    {
        $arrInserts = [];

        foreach (array_keys($arrUpdate) as $fieldName) {
            if (!is_array($this->feUsersTca['columns'][$fieldName])) {
                continue;
            }

            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            if (!$fieldConfig['MM']) {
                continue;
            }

            if ($fieldConfig['type'] != 'select' && !($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'db')) {
                continue;
            }

            $arrRelations = GeneralUtility::trimExplode(',', $arrUpdate[$fieldName]);

            $arrUpdate[$fieldName] = count($arrRelations);

            $arrInserts[$fieldConfig['MM']] = array_merge(($fieldConfig['MM_match_fields'] && $fieldConfig['MM_match_fields']['tablenames'] == 'fe_users') ? ['user_field' => 'uid_foreign', 'record_field' => 'uid_local'] : ['user_field' => 'uid_local', 'record_field' => 'uid_foreign'], ['records' => [], 'match_fields' => (array)$fieldConfig['MM_match_fields'], 'insert_fields' => (array)$fieldConfig['MM_insert_fields']]);

            foreach ($arrRelations as $relation) {
                $arrInserts[$fieldConfig['MM']]['records'][] = intval(($dividerPosition = strripos($relation, '_')) ? substr($relation, $dividerPosition + 1) : $relation);
            }
        }

        return $arrInserts;
    }

    /**
     * Ersetzt die aktuellen Relationen mit den neuen hier uebergebenen Relationen.
     */
    public function insertRelationInserts(int $userId, array $arrInserts = []): void
    {
        foreach ($arrInserts as $foreignTable => $arrInsert) {
            $rows = [];

            $sorting = 1;

            foreach ($arrInsert['records'] as $recordId) {
                $recordId = intval($recordId);

                if ($recordId <= 0) {
                    continue;
                }

                $rows[] = array_merge($arrInsert['match_fields'], $arrInsert['insert_fields'], ['sorting' => $sorting, $arrInsert['user_field'] => $userId, $arrInsert['record_field'] => $recordId]);

                $sorting++;
            }
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($foreignTable);
            $predicates = [];
            $predicates[] = $queryBuilder->expr()->eq(
                $arrInsert['user_field'],
                $userId
            );
            //$where = $arrInsert['user_field'] . ' = ' . $userId;

            foreach ($arrInsert['match_fields'] as $field => $value) {
                //$where .= ' AND ' . $field . ' = "' . $value . '"';

                $predicates[] = $queryBuilder->expr()->eq(
                    $field,
                    $queryBuilder->quote($value)
                );
            }
            $queryBuilder->delete($foreignTable)->where(
                ...$predicates
            )->executeStatement();
            //$this->databaseConnection->exec_DELETEquery($foreignTable, $where);

            if (count($rows) > 0) {
                $queryBuilder->resetQueryParts();
                $queryBuilder->insert($foreignTable)->values($rows);
                //$this->databaseConnection->exec_INSERTmultipleRows($foreignTable, array_keys($rows[0]), $rows);
            }
        }
    }

    /**
     * Erledigt allen Output der nichts mit dem eigendlichen Formular zu tun hat.
     * Fuer besondere Faelle kann hier eine Ausnahme, oder zusaetzliche Konfigurationen gesetzt werden.
     *
     * @return  string      $label
     */
    public function showOutputRedirect(string $mode, string $submode = '', array $params = []): string
    {
        $redirect = true;
        $autologin = false;

        $labelKey = $mode;
        $redirectKey = $mode;

        if ($submode) {
            $labelKey .= '_' . $submode;

            // Wenn für den Submode eine eigene Weiterleitungsseite definiert ist, diese benutzen!
            if ($this->conf['redirect.'][$redirectKey . '_' . $submode]) {
                $redirectKey .= '_' . $submode;
            }
        }

        // Label ermitteln
        $label = $this->getLabel($labelKey, false);

        // Zusaetzliche Konfigurationen die gesetzt werden, bevor die Ausgabe oder der Redirect ausgefuehrt werden.
        switch ($mode) {
            case self::showtypeKeyRegister:
            case 'doubleoptin':
                // Login vormerken.
								// TODO check security of the already existing autologin feature
                if ($params['autologin']) {
                    $autologin = true;
                }

                break;

            case self::showtypeKeyEdit:
                if ($params['refresh']) {
                    // Einen Refresh der aktuellen Seite am Client ausfuehren.
                    $this->frontendController->additionalHeaderData['refresh'] = '<meta http-equiv="refresh" content="2; url=' . $params['refresh'] . '" />';
                }

                break;
        }

        // Hook bevor irgendeine Ausgabe oder eine Weiterleitung stattfindet.
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['showOutputRedirect'])) {
            $_params = ['variables' => ['mode' => $mode, 'submode' => $submode, 'params' => $params], 'parameters' => ['label' => &$label, 'redirect' => &$redirect, 'autologin' => &$autologin, 'redirectKey' => &$redirectKey]];

            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['showOutputRedirect'] as $_funcRef) {
                GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }

        // Login vollziehen, falls eine Redirectseite angegeben ist, wird dorthin automatisch umgeleitet.
        if ($autologin) {
            $this->utils->userAutoLogin($this->userId, $this->conf['redirect.'][$redirectKey], $this->getHiddenParamsArray());
        }

        // Redirect vollziehen, falls angegeben!
        if ($this->conf['redirect.'][$redirectKey]) {
            $this->utils->userRedirect($this->conf['redirect.'][$redirectKey], $this->getHiddenParamsArray());
        }

        return '<div class="' . $mode . ' ' . $submode . '">' . $label . '</div>';
    }

    /**
     * Sendet die Aktivierungsmail an den uebergebenen User.
     *
     * @param integer $userId
     */
    public function sendActivationMail(int $userId = 0): void
    {
        //$userId = intval($userId);

        if (!$userId) {
            $userId = $this->userId;
        }

        // Neuen Timestamp setzten, damit jede Aktivierungsmail einen anderen Hash hat.
        //$this->databaseConnection->exec_UPDATEquery('fe_users', 'uid = ' . $userId, ['tstamp' => time()]);
        $this->repository->update($userId, ['tstamp' => time()]);
        // Userdaten ermitteln.
        $row = $this->repository->findOneByUid($userId);
        //$res = $this->databaseConnection->exec_SELECTquery('uid, tstamp, tx_datamintsfeuser_approval_level', 'fe_users', 'uid = ' . $userId, '', '', '1');
        //$row = $this->databaseConnection->sql_fetch_assoc($res);

        // Genehmigungstypen aufsteigend sortiert ermitteln. Das ist noetig um das Level dem richtigen Typ zuordnen zu koennen.
        // Beispiel: approvalcheck = ,doubleoptin,adminapproval => beim exploden kommt dann ein leeres Arrayelement herraus, das nach dem entfernen einen leeren Platz uebrig laesst.
        $arrApprovalTypes = $this->getApprovalTypes();

        // Aktuellen Genehmigungstyp ermitteln.
        $approvalType = $arrApprovalTypes[count($arrApprovalTypes) - $row['tx_datamintsfeuser_approval_level']];

        // Mail vorbereiten.
        $urlParameters = [$this->prefixId => [$this->contentId => [self::submitparameterKeyMode => self::modeKeyApprovalcheck, 'uid' => $userId]]];
        $approvalParameters = [$this->prefixId => [$this->contentId => [self::submitparameterKeyHash => md5('approval' . $userId . $row['tstamp'] . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])]]];
        $disapprovalParameters = [$this->prefixId => [$this->contentId => [self::submitparameterKeyHash => md5('disapproval' . $userId . $row['tstamp'] . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'])]]];

        // Fuegt die hidden Params mit den Approvalcheck Parametern zusammen.
        $tmpParameters = $this->getHiddenParamsArray();

        ArrayUtility::mergeRecursiveWithOverrule($tmpParameters, $urlParameters);

        $tmpApprovalParameters = $tmpParameters;
        $tmpDisapprovalParameters = $tmpParameters;

        ArrayUtility::mergeRecursiveWithOverrule($tmpApprovalParameters, $approvalParameters);
        ArrayUtility::mergeRecursiveWithOverrule($tmpDisapprovalParameters, $disapprovalParameters);

        $extraMarkers = ['approvallink' => GeneralUtility::locationHeaderUrl($this->utils->escapeBrackets($this->pi_getPageLink($this->frontendController->id, '', $tmpApprovalParameters))), 'disapprovallink' => GeneralUtility::locationHeaderUrl($this->utils->escapeBrackets($this->pi_getPageLink($this->frontendController->id, '', $tmpDisapprovalParameters)))];

        // E-Mail senden.
        $this->sendMail($userId, $approvalType, $this->isAdminApprovalType($approvalType), $this->getConfigurationByShowtype(), $extraMarkers);

        // Cookie fuer das erneute zusenden des Aktivierungslinks setzten.
        $this->setNotActivatedCookie($userId);
    }

    /**
     * @throws InvalidPasswordHashException
     */
    protected function hashPassword(string $plaintextPassword): string
    {
        $passwordHashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
        $passwordHasher = $passwordHashFactory->getDefaultHashInstance('FE');
        return $passwordHasher->getHashedPassword($plaintextPassword);
    }

		protected function isPasswordSameAsCurrentFeUserPassword(string $plaintextPassword): bool
		{
			$passwordHashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
			$passwordHasher = $passwordHashFactory->getDefaultHashInstance('FE');
			return $passwordHasher->checkPassword($plaintextPassword, $this->frontendController->fe_user->user['password']);
		}

    /**
     * Ueberprueft ob die Linkbestaetigung gueltig ist und aktiviert gegebenenfalls den User.
     * @throws \Doctrine\DBAL\Exception
     */
    public function doApprovalCheck(): string
    {
        // Userdaten ermitteln.
        //$res = $this->databaseConnection->exec_SELECTquery('uid, tstamp, tx_datamintsfeuser_approval_level', 'fe_users', 'uid = ' . $this->userId . ' AND pid = ' . $this->storagePageId, '', '', '1');
        $row = $this->repository->findOneByUid($this->userId);
        //$row = $this->databaseConnection->sql_fetch_assoc($res);

        // Genehmigungstyp ermitteln um die richtige E-Mail zu senden, bzw. die richtige Ausgabe zu ermitteln.
        $arrApprovalTypes = array_slice($this->getApprovalTypes(), -$row['tx_datamintsfeuser_approval_level']);
        $approvalType = array_shift($arrApprovalTypes);

        // Wenn kein Genehmigungstyp ermittelt werden konnte.
        if (!$approvalType) {
            return $this->showOutputRedirect(self::modeKeyApprovalcheck, self::submodeKeyFailure);
        }

        $submodePrefix = (count($arrApprovalTypes) > 0) ? implode('_', $arrApprovalTypes) . '_' : '';

        // Ausgabe vorbereiten.
        $mode = $approvalType;
        $submode = $submodePrefix . self::submodeKeyFailure;
        $params = [];

        // Daten vorbereiten.
        $hashApproval = md5('approval' . $row['uid'] . $row['tstamp'] . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        $hashDisapproval = md5('disapproval' . $row['uid'] . $row['tstamp'] . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        // Wenn der Approval-Hash richtig ist, des letzte Genehmigungslevel aber noch nicht erreicht ist.
        if ($this->piVars[$this->contentId][self::submitparameterKeyHash] == $hashApproval && $row['tx_datamintsfeuser_approval_level'] > 1) {
            // Genehmigungslevel updaten.
            $this->repository->update($this->userId, ['tstamp' => time(), 'tx_datamintsfeuser_approval_level' => $row['tx_datamintsfeuser_approval_level'] - 1]);
            //$this->databaseConnection->exec_UPDATEquery('fe_users', 'uid = ' . $this->userId, ['tstamp' => time(), 'tx_datamintsfeuser_approval_level' => $row['tx_datamintsfeuser_approval_level'] - 1]);

            // Aktivierungsmail schicken.
            $this->sendActivationMail();

            // Ausgabe vorbereiten.
            $submode = $submodePrefix . self::submodeKeySuccess;
        }

        // Wenn der Approval-Hash richtig ist, und das letzte Genehmigungslevel erreicht ist.
        if ($this->piVars[$this->contentId][self::submitparameterKeyHash] == $hashApproval && $row['tx_datamintsfeuser_approval_level'] == 1) {
            // User aktivieren.
            //$this->databaseConnection->exec_UPDATEquery('fe_users', 'uid = ' . $this->userId, ['tstamp' => time(), 'disable' => '0', 'tx_datamintsfeuser_approval_level' => '0']);
            $this->repository->update($this->userId, ['tstamp' => time(), 'disable' => 0, 'tx_datamintsfeuser_approval_level' => 0]);
            // Registrierungs E-Mail schicken.
            if ($this->getConfigurationByShowtype('sendadminmail')) {
                $this->sendMail($this->userId, 'registration', true, $this->getConfigurationByShowtype());
            }

            if ($this->getConfigurationByShowtype('sendusermail')) {
                // Erstellt ein neues Passwort, falls Passwort generieren eingestellt ist. Das Passwort kannn dann ueber den Marker "###PASSWORD###" mit der Registrierungsmail gesendet werden.
                $extraMarkers = $this->getPasswordForMail();

                $this->sendMail($this->userId, 'registration', false, $this->getConfigurationByShowtype(), $extraMarkers);
            }

            // Ausgabe vorbereiten.
            $submode = self::submodeKeySuccess;
            $params = ['autologin' => $this->getConfigurationByShowtype('autologin')];
        }

        // Wenn der Disapproval-Hash richtig ist.
        if ($this->piVars[$this->contentId][self::submitparameterKeyHash] == $hashDisapproval) {
            // Wenn der User deaktiviert wird, eine Account-Abgelehnt Mail senden (wenn User ablehnt an den Administrator, oder andersrum).
            if ($this->getConfigurationByShowtype('sendadminmail') && !$this->isAdminApprovalType($approvalType) && !$this->getConfigurationByShowtype('userdelete')) {
                $this->sendMail($this->userId, 'disapproval', true, $this->getConfigurationByShowtype());
            }

            if ($this->getConfigurationByShowtype('sendusermail') && $this->isAdminApprovalType($approvalType)) {
                $this->sendMail($this->userId, 'disapproval', false, $this->getConfigurationByShowtype());
            }

            // User erst loeschen nachdem die Mail an den User gesendet wurde, falls der Admin diesen ablehnt.
            $this->deleteUser();

            // Ausgabe vorbereiten.
            $submode = $submodePrefix . self::submodeKeyUserdelete;
        }

        return $this->showOutputRedirect($mode, $submode, $params);
    }

    /**
     * Ermittelt alle Genehmigungstypen.
     * Wird benoetigt um das Level dem richtigen Typ zuordnen zu koennen.
     */
    public function getApprovalTypes(): array
    {
        // Beispiel: approvalcheck = ,doubleoptin,adminapproval => Beim Exploden kommt dann ein leeres Arrayelement heraus, das nach dem entfernen einen leeren Platz uebrig lassen wuerde.
        return array_unique(array_values(GeneralUtility::trimExplode(',', (string)$this->getConfigurationByShowtype('approvalcheck'), true)));
    }

    /**
     * Setzt einen Cookie fuer den neu angelegten Account, falls dieser aktiviert werden muss.
     *
     * @param integer $userId
     */
    public function setNotActivatedCookie(int $userId): void
    {
        $arrNotActivated = $this->getNotActivatedUserArray();
        $arrNotActivated[] = intval($userId);

        setcookie($this->prefixId . '[not_activated]', implode(',', $arrNotActivated), ['expires' => time() + 60 * 60 * 24 * 30]);
    }

    /**
     * Ermittelt alle nicht aktivierten Accounts des Users.
     *
     * @param array $arrNotActivated
     * @return  array       $arrNotActivatedCleaned
     */
    public function getNotActivatedUserArray(array $arrNotActivated = []): array
    {
        $arrNotActivatedCleaned = [];

        // Nicht aktivierte User ueber den Cookie ermitteln, und vor Missbrauch schuetzen.
        if (!$arrNotActivated) {
            $arrNotActivated = array_unique(array_map('intval', GeneralUtility::trimExplode(',', $_COOKIE[$this->prefixId]['not_activated'], true)));
        }

        // Wenn nach dem reinigen noch User uebrig bleiben.
        if (count($arrNotActivated) > 0) {
            // Herrausgefundene User ermitteln und ueberpruefen, ob die User mitlerweile schon aktiviert wurden.
            //          $res = $this->databaseConnection->exec_SELECTquery('uid', 'fe_users', 'uid IN(' . implode(',', $arrNotActivated) . ') AND disable = 1 AND deleted = 0');
            $rows = $this->repository->findByUidsAndDisable($arrNotActivated, 1);
            foreach ($rows as $row) {
                $arrNotActivatedCleaned[] = $row['uid'];
            }
        }

        return $arrNotActivatedCleaned;
    }

    /**
     * Sendet die E-Mails mit dem uebergebenen Template und falls angegeben, auch mit den extra Markern.
     *
     * @param integer $userId
     * @param boolean $adminMail
     * @param array $config
     * @param array $extraMarkers
     * @param array $extraSuparts
     * @throws \Doctrine\DBAL\Exception
     */
    public function sendMail(int $userId, string $templatePart, bool $adminMail, array $config, array $extraMarkers = [], array $extraSuparts = []): void
    {
        // Userdaten ermitteln.
        if ($this->userId) {
            $row = $this->getValuesForMail();
        } else {
            $row = $this->repository->findOneByUid($userId);
        }

        $arrSpecialMarkers = ['siteurl' => GeneralUtility::getIndpEnv('TYPO3_SITE_URL'), 'requesturl' => GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL')];

        $markerArray = array_merge($arrSpecialMarkers, (array)$config, (array)$row, (array)$extraMarkers);

        foreach ($markerArray as $key => $val) {
            $markerArray['label_' . $key] = $this->getLabel($key, false);
        }

        // Absender vorbereiten.
        $fromName = $config['sendername'];
        $fromEmail = $config['sendermail'];

        $replytoName = empty($config['replytoname']) ? $fromName : $config['replytoname'];
        $replytoEmail = empty($config['replytomail']) ? $fromEmail : $config['replytomail'];

        // Wenn die Mail fuer den Admin bestimmt ist.
        if ($adminMail) {
            // Template laden.
            $content = $this->getTemplateSubpart($templatePart . '_admin', $markerArray, $config);

            $toName = $config['adminname'];
            $toEmail = $config['adminmail'];
        } else {
            // Template laden.
            $content = $this->getTemplateSubpart($templatePart, $markerArray, $config);

            $toName = $markerArray['username'];
            $toEmail = $markerArray['email'];
        }

        // Betreff ermitteln und aus dem E-Mail Content entfernen.
        $subject = trim($this->templateService->getSubpart($content, '###SUBJECT###'));
        $content = $this->templateService->substituteSubpart($content, '###SUBJECT###', '');

        // Body zusammensetzen.
        $body = $this->getTemplateSubpart('body', array_merge($markerArray, ['content' => $content]), $config);

        // Header ermitteln und Betreff ersetzten (Title-Tag).
        $header = $this->getTemplateSubpart('header', array_merge($markerArray, ['subject' => $subject]), $config);

        // Extra Subparts ersetzten.
        foreach ($extraSuparts as $key => $val) {
            $body = $this->templateService->substituteSubpart($body, '###' . strtoupper($key) . '###', $val);
        }

        // Hook um die E-Mail zu aendern.
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['sendMail'])) {
            $_params = ['variables' => ['userId' => $userId, 'templatePart' => $templatePart, 'adminMail' => $adminMail, 'config' => $config, 'markerArray' => $markerArray], 'parameters' => ['body' => &$body, 'header' => &$header, 'subject' => &$subject, 'toName' => &$toName, 'toEmail' => &$toEmail, 'fromName' => &$fromName, 'fromEmail' => &$fromEmail, 'replytoName' => &$replytoName, 'replytoEmail' => &$replytoEmail]];

            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$this->extKey]['sendMail'] as $_funcRef) {
                GeneralUtility::callUserFunction($_funcRef, $_params, $this);
            }
        }

        // Verschicke E-Mail.
        if ($toEmail && $subject && $body) {
            $bodyHtml = '<html>' . $header . $body . '</html>';
            $bodyPlain = trim(strip_tags($body));

            if ($config['mailtype'] == 'html') {
                $bodyPlain = $this->utils->convertHtmlEmailToPlain($bodyHtml);
            }

            $mail = GeneralUtility::makeInstance(MailMessage::class);
            assert($mail instanceof MailMessage);
            $mail->setSubject($subject);
            $mail->setFrom([$fromEmail => $fromName]);
            $mail->setReplyTo([$replytoEmail => $replytoName]);
            $mail->setTo([$toEmail => $toName]);
            $mail->html($bodyPlain);

            if ($config['mailtype'] == 'html') {
                $mail->html($bodyHtml);
                $mail->text($bodyPlain);
            }

            $mail->send();
        }
    }

    /**
     * Ueberprueft anhand des Genehmigungstyps ob die Mail eine Adminmail oder eine Usermail ist. Wenn 'admin' im Namen des Genehmigungstyps steht, dann ist die Mail eine Adminmail.
     *
     * @param string $approvalType
     */
    public function isAdminApprovalType($approvalType): bool
    {
        return str_contains($approvalType, 'admin');
    }

    /**
     * Holt einen Subpart des Standardtemplates und ersetzt uebergeben Marker.
     */
    public function getTemplateSubpart(string $templatePart, array $markerArray = [], array $config = []): string
    {
        // Template holen.
        $templateFile = $config['emailtemplate'];

        if (!$templateFile) {
            $templateFile = 'EXT:' . $this->extKey . '/Resources/Private/datamints_feuser_mail.html';
        }

        // Template laden.
        return $this->utils->getTemplateSubpart($templateFile, $templatePart, $markerArray);
    }

    /**
     * Ermittlet alle Userdaten und schreibt diese in ein Markerarray.
     *
     * @return  array       $extraMarkers
     * @throws \Doctrine\DBAL\Exception
     */
    public function getValuesForMail(): array
    {
        $extraMarkers = [];

        // Userdaten ermitteln.
        $row = $this->repository->findOneByUid($this->userId);
        //$res = $this->databaseConnection->exec_SELECTquery('*', 'fe_users', 'uid = ' . $this->userId, '', '', '1');
        //$row = $this->databaseConnection->sql_fetch_assoc($res);

        // ToDo: MM-Relation, Merge MM-Values erweitern.
        $arrCurrentData = $this->mergeRelationValues($this->userId, $row);

        // Alle ausgewaehlten Felder durchgehen.
        foreach ($arrCurrentData as $fieldName => $rawValue) {
            $value = $rawValue;
            //$fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];

            // Wenn das Feld existiert.
            if (isset($this->feUsersTca['columns'][$fieldName])) {
                $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
                switch ($fieldConfig['type']) {
                    case 'input':
                        break;
                    case 'date':
                        $value = date($this->conf['format.']['date'], $rawValue);
                        break;
                    case 'datetime':
                        $value = date($this->conf['format.']['datetime'], $rawValue);
                        break;
                    case 'password':
                        $value = $this->getLabel('password_placeholder', false);
                        break;
                    case 'check':
                        if (count((array)$fieldConfig['items']) > 1) {
                            $value = [];

                            if (!is_array($rawValue)) {
                                $rawValue = str_split(strrev(decbin($rawValue)));
                            }

                            foreach (array_values($fieldConfig['items']) as $key => $checkItem) {
                                if ($rawValue[$key]) {
                                    $value[] = $this->getLabel($checkItem['label'], false);
                                }
                            }

                            $value = implode(', ', $value);
                        } else {
                            $value = ($rawValue) ? $this->getLabel('check_yes', false) : $this->getLabel('check_no', false);
                        }

                        break;

                    case 'radio':
                        $value = '';

                        if (is_array($fieldConfig['items'])) {
                            foreach (array_values($fieldConfig['items']) as $radioItem) {
                                if ($rawValue == $radioItem[1]) {
                                    $value = $this->getLabel($radioItem['label'], false);
                                }
                            }
                        }

                        break;

                    case 'select':
                        $value = [];

                        if (!is_array($rawValue)) {
                            $rawValue = GeneralUtility::trimExplode(',', $rawValue, true);
                        }

                        if (is_array($fieldConfig['items'])) {
                            foreach ($fieldConfig['items'] as $selectItem) {
                                if (in_array($selectItem[1], $rawValue)) {
                                    $value[] = $this->getLabel($selectItem['label'], false);
                                }
                            }
                        }

                        if ($fieldConfig['foreign_table']) {
                            $table = $fieldConfig['foreign_table'];

                            $labelFieldName = $this->getTableLabelFieldName($table);

                            // Select-Items aus DB holen.
                            //$select = 'uid, ' . $labelFieldName;

                            // Falls kein AND, OR, GROUP BY, ORDER BY oder LIMIT am Anfang des where steht, ein AND voranstellen!
                            $options = strtolower(substr(trim((string)$fieldConfig['foreign_table_where']), 0, 3));
                            $options = trim((!$options || $options === 'and' || $options === 'or ' || $options === 'gro' || $options === 'ord' || $options === 'lim') ? $fieldConfig['foreign_table_where'] : 'AND ' . $fieldConfig['foreign_table_where']);
                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                            $connection = $queryBuilder->getConnection();
                            $result = $connection->executeQuery('SELECT uid, ' . $labelFieldName . ' FROM ' . $table . ' WHERE 1=1 ' . $options);

                            //$res = $this->databaseConnection->exec_SELECTquery($select, $table, '1 ' . $this->pageRepository->enableFields($table) . ' ' . $options);

                            while ($row = $result->fetchAssociative()) {
                                if (in_array($row['uid'], $rawValue)) {
                                    $value[] = $row[$labelFieldName];
                                }
                            }
                        }

                        $value = implode(', ', $value);

                        break;

                    case 'group':
                        if ($fieldConfig['internal_type'] == 'db' && is_array($rawValue)) {
                            $value = [];

                            $arrItems = [];
                            $arrAllowed = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);

                            foreach ($arrAllowed as $table) {
                                if (!$GLOBALS['TCA'][$table]) {
                                    continue;
                                }

                                $labelFieldName = $this->getTableLabelFieldName($table);

                                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                                $result = $queryBuilder->select('uid', $labelFieldName)->from($table)->executeQuery();

                                //$res = $this->databaseConnection->exec_SELECTquery('uid, ' . $labelFieldName, $table, '1 ' . $this->pageRepository->enableFields($table));

                                while ($row = $result->fetchAssociative()) {
                                    $arrItems[$table . '_' . $row['uid']] = $row[$labelFieldName];
                                }
                            }

                            foreach ($arrItems as $key => $label) {
                                if (array_intersect([$key, substr($key, strripos($key, '_') + 1)], $rawValue)) {
                                    $value[] = $label;
                                }
                            }

                            $value = implode(', ', $value);
                        }

                        break;
                }
            }

            $extraMarkers[$fieldName] = $value;
            $extraMarkers[$fieldName . '_raw'] = $rawValue;
        }

        return $extraMarkers;
    }

    /**
     * Ermittlet alle geaenderten Userdaten und schreibt diese in ein Markerarray.
     *
     * @param array $config
     * @return  array       $extraMarkers
     */
    public function getChangedForMail(array $arrNewData, $config): array
    {
        $count = 0;
        $template = $this->getTemplateSubpart('changed_items', [], $config);
        $extraMarkers = [];

        foreach ($this->arrUsedFields as $fieldName) {
            if ($arrNewData[$fieldName] != $this->frontendController->fe_user->user[$fieldName]) {
                $markerArray = [];
                $markerArray['label'] = $this->getLabel($fieldName, false);
                $markerArray['value_old'] = $this->frontendController->fe_user->user[$fieldName];
                $markerArray['value_new'] = $arrNewData[$fieldName];

                $subpart = $this->templateService->getSubpart($template, '###' . strtoupper((string)$fieldName) . '###');

                if ($subpart) {
                    $count++;
                    $extraMarkers['changed_item_' . $fieldName] = $this->templateService->substituteMarkerArray($subpart, $markerArray, '###|###', true);
                } else {
                    $extraMarkers['changed_item_' . $fieldName] = '';
                }
            } else {
                $extraMarkers['changed_item_' . $fieldName] = '';
            }
        }

        if (!$count) {
            $extraMarkers['nothing_changed'] = 'nothing_changed';
        }

        return $extraMarkers;
    }

    /**
     * Erstellt ein neues Passwort, falls Passwort generieren eingestellt ist.
     * Dieses Passwort kannn dann ueber den Marker "###PASSWORD###" mit der Registrierungsmail gesendet werden.
     *
     * @return  array       $extraMarkers
     */
    public function getPasswordForMail(): array
    {
        $extraMarkers = [];
        $generatePassword = $this->getConfigurationByShowtype('generatepassword.');

        if ($generatePassword['mode'] && $this->userId) {
            $password = $this->utils->generatePassword($this->piVars[$this->contentId]['password'], $generatePassword);

            $extraMarkers['password'] = $password['normal'];

            $this->repository->update($this->userId, ['password' => $password['encrypted']]);
            //$this->databaseConnection->exec_UPDATEquery('fe_users', 'uid = ' . $this->userId, ['password' => $password['encrypted']]);
        }

        return $extraMarkers;
    }

    /**
     * Gibt alle im Backend definierten Felder (TypoScipt/Flexform) formatiert und der Anzeigeart entsprechend aus.
     */
    public function showForm(array $valueCheck = []): string
    {
        $arrCurrentData = [];

        // Beim editieren der Userdaten, die Felder vorausfuellen.
        if ($this->conf['showtype'] == self::showtypeKeyEdit) {
            if (is_array($this->frontendController->fe_user->user)) {
                $arrCurrentData = $this->frontendController->fe_user->user;
            }

            // ToDo: MM-Relation, Merge MM-Values erweitern.
            $arrCurrentData = $this->mergeRelationValues($this->userId, $arrCurrentData);
        }

        // Wenn das Formular schon einmal abgesendet wurde, aber ein Fehler auftrat, dann die bereits vom User uebertragenen Userdaten vorausfuellen.
        if (is_array($this->piVars[$this->contentId])) {
            $arrCurrentData = array_merge($arrCurrentData, $this->piVars[$this->contentId]);
        }

        // Alle moeglichen Zeichen der Ausgabe, die stoeren koennten (XSS) konvertieren / entfernen.
        $this->utils->htmlspecialcharsPostArray($arrCurrentData, false);

        // Seite, die den Request entgegennimmt (TypoLink).
        $requestLink = $this->pi_getPageLink($this->conf['requestpid']);

        // Wenn keine Seite per TypoScript angegeben ist, wird die aktuelle Seite verwendet.
        if (!$this->conf['requestpid']) {
            $requestLink = $this->pi_getPageLink($this->frontendController->id);
        }

        // Zum Formular springen wenn z.B. ein Fehler aufgetreten ist.
        if ($this->conf['requestanchor']) {
            $requestLink .= '#c' . $this->contentId;
        }

        // ID Zaehler fuer Items und Fieldsets.
        $iItem = 1;
        $iFieldset = 1;
        $iInfoItem = 1;

        // Formular start.
        $formClassName = $this->conf['form.']['class'] ?: '';
        $content = '<form name="' . $this->prefixId . '[' . $this->contentId . ']" action="' . $requestLink . '" method="post" enctype="multipart/form-data" id="' . $this->getFieldId('form') . '" class="' . $formClassName . '">';
        $content .= '<fieldset class="group-' . $iFieldset . '">';

        // Wenn eine Lgende fuer das erste Fieldset definiert wurde, diese ausgeben.
        if ($this->conf['legends.'][$iFieldset]) {
            $content .= '<legend>' . $this->utils->currentUserWrap($this->conf['legends.'][$iFieldset], $this->conf['legends.'][$iFieldset . '.']) . '</legend>';
        }

        // Alle ausgewaehlten Felder durchgehen.
        foreach ($this->arrUsedFields as $fieldName) {
            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            $disabledField = ($fieldConfig['readOnly']) ? ' disabled="disabled"' : '';

            // Standardkonfigurationen laden.
            if (!isset($arrCurrentData[$fieldName]) && $fieldConfig['default']) {
                $arrCurrentData[$fieldName] = $fieldConfig['default'];
            }

            // Wenn das im Flexform ausgewaehlte Feld existiert, dann dieses Feld ausgeben, alle anderen Felder werden ignoriert.
            if ($this->feUsersTca['columns'][$fieldName]) {
                // Form Item Anfang.
                $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, $fieldConfig['type'], $valueCheck) . '">';


                if ($fieldConfig['type'] !== 'check') {
                    // Label schreiben.
                    $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . '</label>';
                }

                switch ($fieldConfig['type']) {
                    case 'password':
                    case 'email':
                    case 'input':
                        $content .= $this->showInput($fieldName, $fieldConfig, $arrCurrentData, $disabledField, $valueCheck, $iItem);

                        break;

                    case 'text':
                        $content .= $this->showText($fieldName, $fieldConfig, $arrCurrentData, $disabledField);

                        break;

                    case 'check':
                        $content .= $this->showCheck($fieldName, $fieldConfig, $arrCurrentData, $disabledField);

                        break;

                    case 'radio':
                        $content .= $this->showRadio($fieldName, $fieldConfig, $arrCurrentData, $disabledField);

                        break;

                    case 'select':
                        $content .= $this->showSelect($fieldName, $fieldConfig, $arrCurrentData, $disabledField);

                        break;

                    case 'group':
                        if ($fieldConfig['internal_type'] == 'file') {
                            $arrCurrentData[$fieldName] = $this->frontendController->fe_user->user[$fieldName];
                        }

                        $content .= $this->showGroup($fieldName, $fieldConfig, $arrCurrentData, $disabledField);

                        break;
                }

                // Extra Error Label ermitteln.
                $content .= $this->getErrorLabel($fieldName, $valueCheck);

                // Form Item Ende.
                $content .= '</div>';

                $iItem++;
            }

            // Den Feldnamen saeubern und die Spezialfelder anzeigen.
            $fieldName = $this->utils->getSpecialFieldName($fieldName);

            // Submit Button anzeigen.
            if ($fieldName == self::specialfieldKeySubmit) {
                $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName) . '">';
                $content .= '<input type="submit" value="' . $this->getLabel($fieldName . '_' . $this->conf['showtype'], false) . '" id="' . $this->getFieldId($fieldName) . '" />';
                $content .= '</div>';

                $iItem++;
            }

            // Captcha anzeigen.
            if ($fieldName == self::specialfieldKeyCaptcha) {
                $content .= $this->showCaptcha($fieldName, $valueCheck, $iItem);

                $iItem++;
            }

            // Infoitem anzeigen.
            if ($fieldName == self::specialfieldKeyInfoitem) {
                if ($this->conf['infoitems.'][$iInfoItem] || $this->conf['infoitems.'][$iInfoItem . '.']) {
                    $content .= '<div class="' . $this->getFieldClasses($iInfoItem, $fieldName) . '">' . $this->utils->currentUserWrap($this->conf['infoitems.'][$iInfoItem], $this->conf['infoitems.'][$iInfoItem . '.']) . '</div>';
                }

                $iInfoItem++;
            }

            // Separator anzeigen.
            if ($fieldName == self::specialfieldKeySeparator) {
                $iFieldset++;

                $content .= '</fieldset><fieldset class="group-' . $iFieldset . '">';

                // Wenn eine Lgende fuer das Fieldset definiert wurde, diese ausgeben.
                if ($this->conf['legends.'][$iFieldset]) {
                    $content .= '<legend>' . $this->utils->currentUserWrap($this->conf['legends.'][$iFieldset], $this->conf['legends.'][$iFieldset . '.']) . '</legend>';
                }
            }

            // Profil loeschen Link anzeigen.
            if ($fieldName == self::specialfieldKeyUserdelete && $this->conf['showtype'] == self::showtypeKeyEdit) {
                $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, 'check', $valueCheck) . '">';
                $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . '</label>';
                $content .= '<input type="checkbox" name="' . $this->getFieldName($fieldName) . '" value="1" id="' . $this->getFieldId($fieldName) . '" />';
                $content .= $this->getErrorLabel($fieldName, $valueCheck);
                $content .= '</div>';

                $iItem++;
            }

            // Aktivierung erneut senden anzeigen.
            if ($fieldName == self::specialfieldKeyResendactivation) {
                // Noch nicht fertig gestellte Listenansicht der nicht aktivierten User.
                //              if ($this->conf['shownotactivated'] == 'list') {
                //                  $arrNotActivated = $this->getNotActivatedUserArray();
                //                  $res = $this->databaseConnection->exec_SELECTquery('uid, username', 'fe_users', 'pid = ' . $this->storagePageId . ' AND uid IN(' . implode(',', $arrNotActivated) . ') AND disable = 1 AND deleted = 0');
                //
                //                  while ($row = $this->databaseConnection->sql_fetch_assoc($res)) {
                //                      $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName) . ' ' . $this->conf['shownotactivated'] . '">';
                //                      $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . ' ' . $row['username'] . '</label>';
                //                      $content .= '<input type="checkbox" name="' . $this->getFieldName($fieldName, $row['uid']) . '" value="1" id="' . $this->getFieldId($fieldName) . '" />';
                //                      $content .= '</div>';
                //
                //                      $iItem++;
                //                  }
                //              } else {
                $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, 'input', $valueCheck) . '">';
                $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . '</label>';
                $content .= '<input type="text" name="' . $this->getFieldName($fieldName) . '" value="" id="' . $this->getFieldId($fieldName) . '" />';
                $content .= $this->getErrorLabel($fieldName, $valueCheck);
                $content .= '</div>';

                $iItem++;
                //              }
            }

            // Passwortbestaetigung anzeigen.
            if ($fieldName == self::specialfieldKeyPasswordconfirmation && $this->conf['showtype'] == self::showtypeKeyEdit) {
                $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, 'input', $valueCheck) . '">';
                $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . '</label>';
                $content .= '<input type="password" name="' . $this->getFieldName($fieldName) . '" value="" id="' . $this->getFieldId($fieldName) . '" />';
                $content .= $this->getErrorLabel($fieldName, $valueCheck);
                $content .= '</div>';

                $iItem++;
            }
        }

        // UserId, PageId und Modus anhaengen.
        $content .= '<input type="hidden" name="' . $this->getFieldName(self::submitparameterKeyMode) . '" value="' . self::modeKeySend . '" />';
        $content .= '<input type="hidden" name="' . $this->getFieldName(self::submitparameterKeyUser) . '" value="' . $this->userId . '" />';
        $content .= '<input type="hidden" name="' . $this->getFieldName(self::submitparameterKeyPage) . '" value="' . $this->frontendController->id . '" />';
        $content .= '<input type="hidden" name="' . $this->getFieldName(self::submitparameterKeySubmode) . '" value="' . $this->conf['showtype'] . '" />';
        $content .= $this->getHiddenParamsHiddenFields();

        $content .= '</fieldset>';

        return $content . '</form>';
    }

    /**
     * Ersetzt bei Feldern mit einer MM-Relation den Wert aus dem FE User Datensatz (Anzahl der Relationen) mit den eigentlichen IDs der verknuepften Datensaetze.
     * @throws \Doctrine\DBAL\Exception
     */
    public function mergeRelationValues(int $userId, array $arrCurrentData): array
    {
        foreach (array_keys($arrCurrentData) as $fieldName) {
            if (!is_array($this->feUsersTca['columns'][$fieldName])) {
                continue;
            }

            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            if (!$fieldConfig['MM']) {
                continue;
            }

            if ($fieldConfig['type'] != 'select' && !($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'db')) {
                continue;
            }

            $mmTable = $fieldConfig['MM'];

            //$where = '';
            //$mmWhere = '';
            $foreignWhere = '';


            // Falls kein AND, OR, GROUP BY, ORDER BY oder LIMIT am Anfang des where steht, ein AND voranstellen!
            //  $options = strtolower(substr(trim((string)$fieldConfig['MM_table_where']), 0, 3));

            //$mmWhere .= ' ' . trim((!$options || $options === 'and' || $options === 'or ' || $options === 'gro' || $options === 'ord' || $options === 'lim') ? $fieldConfig['MM_table_where'] : 'AND ' . $fieldConfig['MM_table_where']);

            if ($fieldConfig['type'] == 'select') {
                //$arrForeignTables = GeneralUtility::trimExplode(',', $fieldConfig['foreign_table'], true);

                // Falls kein AND, OR, GROUP BY, ORDER BY oder LIMIT am Anfang des where steht, ein AND voranstellen!
                $options = strtolower(substr(trim((string)$fieldConfig['foreign_table_where']), 0, 3));

                $foreignWhere .= ' ' . trim((!$options || $options === 'and' || $options === 'or ' || $options === 'gro' || $options === 'ord' || $options === 'lim') ? $fieldConfig['foreign_table_where'] : 'AND ' . $fieldConfig['foreign_table_where']);
            }

            $arrForeignTables = [];
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'db') {
                $arrForeignTables = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);
            }

            $arrCurrentData[$fieldName] = [];

            foreach ($arrForeignTables as $foreignTable) {
                if (!$GLOBALS['TCA'][$foreignTable]) {
                    continue;
                }


                // Determine local and foreign tables
                $tables = ($fieldConfig['MM_match_fields'] && $fieldConfig['MM_match_fields']['tablenames'] == 'fe_users') ? ['local' => $foreignTable, 'foreign' => 'fe_users'] : ['local' => 'fe_users', 'foreign' => $foreignTable];
                $localTable = $tables['local'];

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable($foreignTable);
                assert($queryBuilder instanceof \TYPO3\CMS\Core\Database\Query\QueryBuilder);
                $queryBuilder->setRestrictions(
                    GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer::class)
                );


                $where = [
                    $queryBuilder->expr()->eq('fe_users.uid', $queryBuilder->createNamedParameter($userId))
                ];

                //$where .= ' AND fe_users.uid = ' . intval($userId);
                //$queryBuilder->expr()->eq('fe_users.uid', $queryBuilder->createNamedParameter($userId));
                foreach ((array)$fieldConfig['MM_match_fields'] as $field => $value) {
                    // $where .= ' AND ' . $mmTable . '.' . $field . ' = "' . $value . '"';
                    $where[] = $queryBuilder->expr()->eq("$mmTable.$field", $queryBuilder->createNamedParameter($value));
                }

                // TODO
                if ($foreignWhere) {
                    $where[] = $foreignWhere;
                }

                $queryBuilder
                    ->select($foreignTable . '.uid')
                    ->from($localTable)
                    ->join(
                        $localTable,
                        $mmTable,
                        'mm',
                        $queryBuilder->expr()->eq(
                            $localTable . '.uid',
                            $queryBuilder->quoteIdentifier('mm.uid_local')
                        )
                    )
                    ->join(
                        'mm',
                        $foreignTable,
                        $foreignTable,
                        $queryBuilder->expr()->eq(
                            $foreignTable . '.uid',
                            $queryBuilder->quoteIdentifier('mm.uid_foreign')
                        )
                    )
                    ->where(
                        $queryBuilder->expr()->and(
                        // Convert your conditions into expressions
                        // E
                        //xample:
                            ...$where
                            //$queryBuilder->expr()->eq('fe_users.some_field', $queryBuilder->createNamedParameter($someValue)),
                            // Add enableFields conditions
                            // If $where, $mmWhere, $foreignWhere are strings, include them carefully
                            // Preferably, rebuild conditions using QueryBuilder expressions for safety
                        )
                    );

                // Execute the query
                $sql = $queryBuilder->getSQL();
                $sql .= $foreignWhere;
                $statement = $queryBuilder->getConnection()->executeQuery($sql);

                // Fetch results
                while ($row = $statement->fetchAssociative()) {
                    $arrCurrentData[$fieldName][] = $row['uid'];
                }


                //              $res = $this->databaseConnection->exec_SELECT_mm_query($foreignTable . '.uid', $tables['local'], $mmTable, $tables['foreign'], $where . $this->pageRepository->enableFields($foreignTable) . $mmWhere . $foreignWhere);


                #$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                #$result = $queryBuilder->select('uid', $labelFieldName)->from($table)->executeQuery();

                //while ($row = $this->databaseConnection->sql_fetch_assoc($res)) {
                //  $arrCurrentData[$fieldName][] = $row['uid'];
                //}
            }
        }

        return $arrCurrentData;
    }

    /**
     * Rendert Inputfelder.
     *
     * @param array $valueCheck
     * @return  string      $content
     */
    public function showInput(string $fieldName, array $fieldConfig, array $arrCurrentData, string $disabledField = '', $valueCheck = [], int $iItem = 0): string
    {
        $content = '';
        $additionalAttributes = '';
        $type = $fieldConfig['type'] ?? 'text';
        if ($type === 'datetime') {
            $type = 'datetime-local';
        }
        //$arrFieldConfigEval = GeneralUtility::trimExplode(',', $fieldConfig['eval'], true);

        // Datumsfeld und Datumzeitfeld.
        if ($type === 'date' || $type === 'datetime-local') {
            // Vom User ausgefuellten Wert vorbelegen, da bei einem Fehler im Formular das Datum nicht in einen Timestamp zurueck konvertiert wird.
            $datum = $arrCurrentData[$fieldName] ?: '';

            // Nur als Datum formatieren, wenn der aktuelle Wert ein Timestamp ist.
            if ($arrCurrentData[$fieldName] && is_numeric($arrCurrentData[$fieldName])) {
                // Timestamp zu "tt.mm.jjjj" machen.
                if ($type === 'date') {
                    $datum = date($this->conf['format.']['date'], $arrCurrentData[$fieldName]);
                }

                // Timestamp zu "hh:mm tt.mm.jjjj" machen.
                if ($type === 'date-local') {
                    $datum = date($this->conf['format.']['datetime'], $arrCurrentData[$fieldName]);
                }
            }

            return $content . ('<input type="' . $type . '" name="' . $this->getFieldName($fieldName) . '" value="' . $datum . '"' . $disabledField . ' id="' . $this->getFieldId($fieldName) . '" />');
        }

        // Passwordfelder.
        if ($type === 'password') {
            $content .= '<input type="password" name="' . $this->getFieldName($fieldName) . '" value=""' . $disabledField . ' id="' . $this->getFieldId($fieldName) . '" />';
            $content .= '</div><div id="' . $this->getFieldId($fieldName, 'rep', 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, $fieldConfig['type'], $valueCheck) . '">';
            $content .= '<label for="' . $this->getFieldId($fieldName, 'rep') . '">' . $this->getLabel($fieldName . '_rep', false) . $this->isRequiredField($fieldName) . '</label>';

            return $content . ('<input type="password" name="' . $this->prefixId . '[' . $this->contentId . '][' . $fieldName . '_rep]" value=""' . $disabledField . ' id="' . $this->getFieldId($fieldName, 'rep') . '" />');
        }

        // Normales Inputfeld.
        $additionalAttributes .= ($fieldConfig['max']) ? ' maxlength="' . $fieldConfig['max'] . '"' : '';

        return $content . ('<input type="' . $type . '" name="' . $this->getFieldName($fieldName) . '" value="' . $arrCurrentData[$fieldName] . '"' . $disabledField . ' id="' . $this->getFieldId($fieldName) . '"' . $additionalAttributes . ' />');
    }

    /**
     * Rendert Textareas.
     *
     * @param string $fieldName
     * @param array $fieldConfig
     * @return  string      $content
     */
    public function showText($fieldName, $fieldConfig, array $arrCurrentData, string $disabledField = ''): string
    {
        $content = '';

        return $content . ('<textarea name="' . $this->getFieldName($fieldName) . '" rows="2" cols="42"' . $disabledField . ' id="' . $this->getFieldId($fieldName) . '">' . $arrCurrentData[$fieldName] . '</textarea>');
    }

    /**
     * Rendert Checkboxen.
     *
     * @param string $fieldName
     * @param array $arrCurrentData
     * @return  string      $content
     */
    public function showCheck($fieldName, array $fieldConfig, $arrCurrentData, string $disabledField = ''): string
    {
        $content = '';

        if (count((array)$fieldConfig['items']) > 1) {
            // ToDo: Logik von Anzeige trennen!
            // Moeglichkeit das der gespeicherte Wert eine Bitmap ist, daher aufsplitten in ein Array, wie es auch von einem abgesendeten Formular kommen wuerde.
            if (!is_array($arrCurrentData[$fieldName])) {
                $arrCurrentData[$fieldName] = str_split(strrev(decbin($arrCurrentData[$fieldName])));
            }

            $content .= '<input type="hidden" name="' . $this->getFieldName($fieldName) . '[]" value="" />';

            $content .= '<div class="list clearfix">';

            $i = 1;

            // Items, die in der TCA-Konfiguration festgelegt wurden.
            foreach (array_values($fieldConfig['items']) as $key => $checkItem) {
                // ToDo: Nicht auf den Key verlassen!
                if ($key > 0 && ($key % $fieldConfig['cols']) == 0) {
                    $content .= '</div><div class="list clearfix">';
                }

                $checked = ($arrCurrentData[$fieldName][$key]) ? ' checked="checked"' : '';

                $content .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . '">';
                $content .= '<input type="checkbox" name="' . $this->getFieldName($fieldName, $key) . '" value="1"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName, 'item', $i) . '" />';
                $content .= '<label for="' . $this->getFieldId($fieldName, 'item', $i) . '">' . $this->getLabel($checkItem['label'], false) . '</label>';
                $content .= '</div>';

                $i++;
            }

            $content .= '</div>';
        } else {
            $checked = ($arrCurrentData[$fieldName]) ? ' checked="checked"' : '';

            $content .= '<label class="label label--checkbox" for="' . $this->getFieldId($fieldName) . '">';
            $content .= '<input type="hidden" name="' . $this->getFieldName($fieldName) . '" value="0" />';
            $content .= '<input class="input input--checkbox" type="checkbox" name="' . $this->getFieldName($fieldName) . '" value="1"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName) . '" />';
            $content .= '<span class="label__checkmark"></span>';
            $content .= $this->getLabel($fieldName) . '</label>';
        }

        return $content;
    }

    /**
     * Rendert Radiobuttons.
     *
     * @param string $fieldName
     * @param array $fieldConfig
     * @return  string      $content
     */
    public function showRadio($fieldName, $fieldConfig, array $arrCurrentData, string $disabledField = ''): string
    {
        $content = '';

        $content .= '<div class="list">';

        $i = 1;

        if (is_array($fieldConfig['items'])) {
            foreach (array_values($fieldConfig['items']) as $radioItem) {
                $checked = ($arrCurrentData[$fieldName] == $radioItem[1]) ? ' checked="checked"' : '';

                $content .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . '">';
                $content .= '<input type="radio" name="' . $this->getFieldName($fieldName) . '" value="' . $radioItem[1] . '"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName, 'item', $i) . '" />';
                $content .= '<label for="' . $this->getFieldId($fieldName, 'item', $i) . '">';
                $content .= $this->getLabel($radioItem['label'], false);
                $content .= '</label>';
                $content .= '</div>';

                $i++;
            }
        }

        return $content . '</div>';
    }

    /**
     * Rendert Selectfelder.
     *
     * @param string $fieldName
     * @param array $fieldConfig
     * @param array $arrCurrentData
     * @return  string      $content
     * @throws \Doctrine\DBAL\Exception
     * @throws AspectPropertyNotFoundException
     * @throws AspectNotFoundException
     */
    public function showSelect($fieldName, $fieldConfig, $arrCurrentData, string $disabledField = ''): string
    {
        $content = '';
        $optionlist = '';

        // ToDo: Logik von Anzeige trennen!
        // Moeglichkeit das der gespeicherte Wert eine kommseparierte Liste ist, daher aufsplitten in ein Array, wie es auch von einem abgesendeten Formular kommen wuerde.
        if (!is_array($arrCurrentData[$fieldName])) {
            $arrCurrentData[$fieldName] = GeneralUtility::trimExplode(',', $arrCurrentData[$fieldName], true);
        }

        // Beim Typ Select gibt es zwei verschidene Rendermodi. Dieser kann "singlebox" (dann ist es eine Selectbox) oder "checkbox" (dann ist es eine Checkboxliste) sein.
        $i = 1;

        // Items, die in der TCA-Konfiguration festgelegt wurden.
        if (is_array($fieldConfig['items'])) {
            foreach (array_values($fieldConfig['items']) as $selectItem) {
                if ($fieldConfig['renderMode'] == 'checkbox') {
                    $checked = in_array($selectItem[1], $arrCurrentData[$fieldName]) ? ' checked="checked"' : '';

                    $optionlist .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . '">';
                    $optionlist .= '<input type="checkbox"  name="' . $this->getFieldName($fieldName) . '[]" value="' . $selectItem[1] . '"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName, 'item', $i) . '" />';
                    $optionlist .= '<label for="' . $this->getFieldId($fieldName, 'item', $i) . '">' . $this->getLabel($selectItem['label'], false) . '</label>';
                    $optionlist .= '</div>';
                } else {
                    $selected = in_array($selectItem[1], $arrCurrentData[$fieldName]) ? ' selected="selected"' : '';

                    $optionlist .= '<option value="' . $selectItem[1] . '"' . $selected . '>' . $this->getLabel($selectItem['label'], false) . '</option>';
                }

                $i++;
            }
        }

        // Wenn Tabelle angegeben zusaetzlich Items aus Datenbank holen.
        if ($fieldConfig['foreign_table']) {
            $table = $fieldConfig['foreign_table'];

            $labelFieldName = $this->getTableLabelFieldName($table);
            $languageFieldName = $this->utils->getLanguageFieldName($table);

            // Select-Items aus DB holen.
            //$select = implode(', ', array_filter(['uid', 'pid', $languageFieldName, $labelFieldName]));

            // Falls kein AND, OR, GROUP BY, ORDER BY oder LIMIT am Anfang des where steht, ein AND voranstellen!
            $options = strtolower(substr(trim((string)$fieldConfig['foreign_table_where']), 0, 3));
            $options = trim((!$options || $options === 'and' || $options === 'or ' || $options === 'gro' || $options === 'ord' || $options === 'lim') ? $fieldConfig['foreign_table_where'] : 'AND ' . $fieldConfig['foreign_table_where']);


            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
            $connection = $queryBuilder->getConnection();
            $result = $connection->executeQuery('SELECT uid,pid ' . $languageFieldName . ',' . $labelFieldName . ' FROM ' . $table . ' WHERE 1=1 ' . $options);


            //$res = $this->databaseConnection->exec_SELECTquery($select, $table, '1 ' . $this->pageRepository->enableFields($table) . ' ' . $options);

            $i = 1;


            $languageId = $this->languageAspect->getId();
            $overlayType = $this->languageAspect->getOverlayType();

            while ($row = $result->fetchAssociative()) {
                // Übersetzung ermitteln.
                if ($overlayType && $languageFieldName && $row[$languageFieldName] != $languageId) {
                    $row = $this->frontendController->sys_page->getLanguageOverlay($table, $row, $this->languageAspect);
                }

                if ($fieldConfig['renderMode'] == 'checkbox') {
                    $checked = in_array($row['uid'], $arrCurrentData[$fieldName]) ? ' checked="checked"' : '';

                    $optionlist .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . '">';
                    $optionlist .= '<input type="checkbox" name="' . $this->getFieldName($fieldName) . '[]" value="' . $row['uid'] . '"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName, 'item', $i) . '" />';
                    $optionlist .= '<label for="' . $this->getFieldId($fieldName, 'item', $i) . '">' . $row[$labelFieldName] . '</label>';
                    $optionlist .= '</div>';
                } else {
                    $selected = in_array($row['uid'], $arrCurrentData[$fieldName]) ? ' selected="selected"' : '';

                    $optionlist .= '<option value="' . $row['uid'] . '"' . $selected . '>' . $row[$labelFieldName] . '</option>';
                }

                $i++;
            }
        }

        // Mehrzeiliges oder Einzeiliges Select (Auswahlliste).
        $multiple = ($fieldConfig['size'] > 1) ? ' size="' . $fieldConfig['size'] . '" multiple="multiple"' : '';

        // Wenn kein Wert in im mehrzeiligen Select oder bei den Checkboxen ausgewählt ist, wurde das Feld nicht mit uebermittelt werden. Somit koennte man nie nichts auswaehlen!
        if ($multiple || $fieldConfig['renderMode'] == 'checkbox') {
            $content .= '<input type="hidden" name="' . $this->getFieldName($fieldName) . '[]" value="" />';
        }

        if ($fieldConfig['renderMode'] == 'checkbox') {
            $content .= '<div class="list">';
            $content .= $optionlist;
            $content .= '</div>';
        } else {
            $content .= '<select name="' . $this->getFieldName($fieldName) . '' . (($multiple) ? '[]' : '') . '"' . $multiple . $disabledField . ' id="' . $this->getFieldId($fieldName) . '">';
            $content .= $optionlist;
            $content .= '</select>';
        }

        return $content;
    }

    /**
     * Rendert Groupfelder (z.B. Dateien oder externe Tabellen).
     */
    public function showGroup(string $fieldName, array $fieldConfig, array $arrCurrentData, string $disabledField = ''): string
    {
        $content = '';

        // Wenn es sich um den Typ FILE handelt && es ein Bild ist, dann ein Vorschaubild erstellen und ein File-Inputfeld anzeigen.
        if ($fieldConfig['internal_type'] == 'file') {
            // Verzeichniss ermitteln.
            $uploadFolder = $this->utils->fixPath($fieldConfig['uploadfolder']);

            // ToDo: Logik von Anzeige trennen!
            $arrCurrentFieldData = GeneralUtility::trimExplode(',', $arrCurrentData[$fieldName], true);

            $content .= '<div class="list">';

            $i = 1;

            for ($key = 0; $key < $fieldConfig['size']; $key++) {
                $filename = $arrCurrentFieldData[$key];

                $content .= '<input type="hidden" name="' . $this->getFieldName($fieldName, 'files', $key) . '" value="' . $filename . '" />';
                $content .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . ' clearfix">';

                // Bild anzeigen.
                if ($fieldConfig['show_thumbs'] && $filename) {
                    $imgTSConfig = $this->conf['thumb.'];
                    $imgTSConfig['file'] = $uploadFolder . $filename;
                    $image = $this->cObj->cObjGetSingle('IMAGE', $imgTSConfig);

                    if ($image) {
                        $content .= '<div class="thumb">' . $image . '</div>';
                    }

                    // ToDo: Falls kein Bild ermittelt werden konnte, leeres div mit den Klassen "thumb none" anzeigen!
                }

                if ($fieldConfig['show_thumbs'] && !$filename) {
                    $content .= '<div class="thumb none"></div>';
                }

                if (!$fieldConfig['show_thumbs'] && $filename) {
                    $content .= '<div class="link"><a href="' . $this->utils->getTypoLinkUrl($uploadFolder . $filename) . '" target="_blank" alt="' . $filename . '">' . $filename . '</a></div>';
                }

                // Upload-Feld anzeigen.
                $content .= '<div class="upload">';
                $content .= '<input type="file" name="' . $this->getFieldName($fieldName, 'upload', $key) . '"' . $disabledField . ' id="' . $this->getFieldId($fieldName, 'upload', $i) . '" />';
                $content .= '</div>';

                if ($filename) {
                    $content .= '<div class="delete">';
                    $content .= '<input type="checkbox" name="' . $this->getFieldName($fieldName, 'delete', $key) . '"' . $disabledField . ' id="' . $this->getFieldId($fieldName, 'delete', $i) . '" />';
                    $content .= '<label for="' . $this->getFieldId($fieldName, 'delete', $i) . '">' . $this->getLabel($fieldName . '_delete', false) . '</label>';
                    $content .= '</div>';
                }

                $content .= '</div>';

                $i++;
            }

            $content .= '</div>';
        }

        // Wenn es sich um den Typ DB handelt.
        // Hier werden absichtlich nur die Erlaubten Tabellen benutzt, da es sonst unmengen an möglichen Optionen geben wuerde!
        if ($fieldConfig['internal_type'] == 'db') {
            $arrItems = [];
            $arrAllowed = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);

            foreach ($arrAllowed as $table) {
                if (!$GLOBALS['TCA'][$table]) {
                    continue;
                }

                $labelFieldName = $this->getTableLabelFieldName($table);

                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll();
                $result = $queryBuilder->select('uid', $labelFieldName)->from($table)->executeQuery();

                //$res = $this->databaseConnection->exec_SELECTquery('uid, ' . $labelFieldName, $table, '1 ' . $this->pageRepository->enableFields($table));

                while ($row = $result->fetchAssociative()) {
                    $arrItems[$table . '_' . $row['uid']] = $row[$labelFieldName];
                }
            }

            $content .= '<input type="hidden" name="' . $this->getFieldName($fieldName) . '[]" value="" />';

            $content .= '<div class="list">';

            $i = 1;

            foreach ($arrItems as $key => $label) {
                // ToDo: Logik von Anzeige trennen!
                // Ist der gespeicherte Wert eine kommseparierte Liste, dann in ein Array aufsplitten, wie es auch von einem abgesendeten Formular kommen wuerde.
                if (!is_array($arrCurrentData[$fieldName])) {
                    $arrCurrentData[$fieldName] = GeneralUtility::trimExplode(',', $arrCurrentData[$fieldName], true);
                }

                $checked = array_intersect([$key, substr($key, strripos($key, '_') + 1)], $arrCurrentData[$fieldName]) ? ' checked="checked"' : '';

                $content .= '<div id="' . $this->getFieldId($fieldName, 'item', $i, 'wrapper') . '" class="item item-' . $i . '">';
                $content .= '<input type="checkbox" name="' . $this->getFieldName($fieldName) . '[]" value="' . $key . '"' . $checked . $disabledField . ' id="' . $this->getFieldId($fieldName, 'item', $i) . '" />';
                $content .= '<label for="' . $this->getFieldId($fieldName, 'item', $i) . '">' . $label . '</label>';
                $content .= '</div>';

                $i++;
            }

            $content .= '</div>';
        }

        return $content;
    }

    /**
     * Rendert ein Captcha.
     *
     * @param string $fieldName
     * @param array $valueCheck
     * @return  string      $content
     */
    public function showCaptcha($fieldName, $valueCheck, int $iItem): string
    {
        $content = '';
        $captcha = '';
        //      $showInput = TRUE;

        if (!ExtensionManagementUtility::isLoaded($this->conf['captcha.']['use'])) {
            return $content;
        }

        if ($this->conf['captcha.']['use'] === 'powermail') {
            $viewHelperInvoker = GeneralUtility::makeInstance(ViewHelperInvoker::class);

            $renderingContext = GeneralUtility::makeInstance(RenderingContextFactory::class)->create();
            $field = new Field();
            $field->_setProperty('uid', $this->contentId);
						/** @var class-string $className */
						$className = GeneralUtility::getClassName(CaptchaViewHelper::class);
            $result = $viewHelperInvoker->invoke(
							$className,
                [
                    'field' => $field,
                    'class' => $this->conf['captcha.']['class'] ?: '',
                ],
                $renderingContext,
            );
            $captcha = $result;
            if ($this->conf['captcha.']['reload_class']) {
                $captcha .= '<span class="' . $this->conf['captcha.']['reload_class'] . '">';
                if ($this->conf['captcha.']['reload_icon_path']) {
                    $reloadIconPath = GeneralUtility::getFileAbsFileName($this->conf['captcha.']['reload_icon_path']);
                    if (!$reloadIconPath) {
                        throw new \Exception('Could not load captcha button');
                    }
                    $reloadIconFileContent = file_get_contents($reloadIconPath);

                    $pos = strpos($reloadIconFileContent, '<svg');
                    if (false === $pos) {
                        $f = finfo_open();
                        $mimeType = finfo_buffer($f, $reloadIconFileContent, FILEINFO_MIME_TYPE);
                        $captcha .= '<img src="data:' . $mimeType . ';base64,' . base64_encode($reloadIconFileContent) . '"/>';
                    } else {
                        $svg = substr($reloadIconFileContent, $pos);
                        $captcha .= $svg;
                    }
                }

                $captcha .= '</span>';
            }
        }

        if (!$captcha) {
            return $content;
        }

        $content .= '<div id="' . $this->getFieldId($fieldName, 'wrapper') . '" class="' . $this->getFieldClasses($iItem, $fieldName, '', $valueCheck) . '">';
        $content .= '<label for="' . $this->getFieldId($fieldName) . '">' . $this->getLabel($fieldName) . '</label>';
        if ($this->getLabel('captcha_info')) {
            $content .= '<div class="captchaInfo"> ' . $this->getLabel('captcha_info') . '</div>';
        }

        $content .= '<div class="captcha">' . $captcha . '</div>';
        $content .= '<input type="text" required="required" name="' . $this->getFieldName($fieldName) . '" value="" id="' . $this->getFieldId($fieldName) . '" />';
        //      $content .= ($showInput) ? '<input type="text" name="' . $this->getFieldName($fieldName) . '" value="" id="' . $this->getFieldId($fieldName) . '" />' : '';
        $content .= $this->getErrorLabel($fieldName, $valueCheck);

        return $content . '</div>';
    }

    /**
     * Ermittelt die ID fuer das uebergebene Feld.
     */
    public function getFieldId(...$arrFuncArgs): string
    {
        if (!func_num_args()) {
            return '';
        }

        $arrParts = [$this->extKey, $this->contentId];

        return implode('_', array_merge($arrParts, $arrFuncArgs));
    }

    /**
     * Liefert die Klassen für den Feld-Wrapper zurück.
     */
    public function getFieldClasses(int $iItem, string $fieldName, ?string $fieldType = '', array $valueCheck = []): string
    {
        $arrParts = ['item', 'item-' . $iItem, 'name-' . $fieldName, (($fieldType) ? 'type-' . $fieldType : ''), ($this->isRequiredField($fieldName) ? 'required' : ''), (($valueCheck) ? trim($this->getErrorClass($fieldName, $valueCheck)) : ''), 'clearfix'];

        return implode(' ', array_filter($arrParts));
    }

    /**
     * Ermittelt den Namen fuer das uebergebene Feld.
     */
    public function getFieldName(...$arrFuncArgs): string
    {
        if (!func_num_args()) {
            return '';
        }

        $arrParts = [$this->prefixId, $this->contentId];

        return array_shift($arrParts) . '[' . implode('][', array_merge($arrParts, $arrFuncArgs)) . ']';
    }

    /**
     * Ermittelt ein bestimmtes Label aufgrund des im TCA gespeicherten Languagestrings, des Datenbankfeldnamens oder gibt einfach den uebergeben Wert wieder aus, wenn nichts gefunden wurde.
     *
     * @param string $fieldName / $languageString
     */
    public function getLabel(string $fieldName, bool $checkRequired = true): string
    {
        if (!str_contains($fieldName, 'LLL:')) {
            // Label aus der Konfiguration holen basierend auf dem Datenbankfeldnamen.
            $label = $this->pi_getLL($fieldName);

            // Das Label zurueckliefern, falls vorhanden.
            if ($label) {
                if ($checkRequired && str_ends_with($label, '*')) {
                    $label = trim($label, ' *');
                }
                return $label . (($checkRequired) ? $this->isRequiredField($fieldName) : '');
            }

            //Label aus der Flexform holen
            $label = $this->getFlexformLabelByFieldName($fieldName);
            if ($label) {
                if ($checkRequired && str_ends_with($label, '*')) {
                    $label = trim($label, ' *');
                }
                return $label;
            }

            // LanguageString ermitteln.
            $languageString = $this->feUsersTca['columns'][$fieldName]['label'];
        } else {
            $languageString = $fieldName;
        }

        // Label aus der Konfiguration holen basierend auf dem languageKey.
        $lstr = GeneralUtility::trimExplode(':', $languageString, true);
        //$l = str_replace('.', '-', array_pop($lstr));
        $label = $this->pi_getLL(array_pop($lstr));

        // Das Label zurueckliefern, falls vorhanden.
        if ($label) {
            if ($checkRequired && str_ends_with($label, '*')) {
                $label = trim($label, ' *');
            }
            return $label . (($checkRequired) ? $this->isRequiredField($fieldName) : '');
        }

        // Das Label zurueckliefern.
        $label = $this->frontendController->sL($languageString);

        // Das Label zurueckliefern, falls vorhanden.
        if ($label) {
            if ($checkRequired && str_ends_with($label, '*')) {
                $label = trim($label, ' *');
            }
            return $label . (($checkRequired) ? $this->isRequiredField($fieldName) : '');
        }

        // Wenn gar nichts gefunden wurde den uebergebenen Wert wieder zurueckliefern.
        return $fieldName . (($checkRequired) ? $this->isRequiredField($fieldName) : '');
    }

    /**
     * @return mixed|string
     */
    public function getFlexformLabelByFieldName(string $fieldName): mixed
    {
        foreach ($this->conf['databasefields'] as $databaseField) {
            if ($databaseField['field'] === $fieldName) {
                return $databaseField['label'];
            }
        }

        return '';
    }

    /**
     * Ermittelt den Fehlertyp aus dem Feldnamen.
     *
     * @param string $fieldName
     * @param array $valueCheck
     * @return  string      $type
     */
    public function getErrorType($fieldName, $valueCheck): string
    {
        if (array_key_exists($fieldName, $valueCheck) && is_string($valueCheck[$fieldName])) {
            return $valueCheck[$fieldName];
        }

        return '';
    }

    /**
     * Ermittelt die Fehlerklasse aus dem Feldnamen.
     *
     * @param string $fieldName
     * @param array $valueCheck
     * @return  string      $class
     */
    public function getErrorClass($fieldName, $valueCheck): string
    {
        // Extra Error Label ermitteln.
        if ($errorType = $this->getErrorType($fieldName, $valueCheck)) {
            return ' error error-' . $errorType;
        }

        return '';
    }

    /**
     * Ermittelt das Fehlerlabel aus dem Feldnamen.
     *
     * @param array $valueCheck
     * @return  string      $label
     */
    public function getErrorLabel(string $fieldName, $valueCheck): string
    {
        // Extra Error Label ermitteln.
        if ($errorType = $this->getErrorType($fieldName, $valueCheck)) {
            return '<div class="error-label error-' . $fieldName . '">' . $this->getLabel($fieldName . '_error_' . $errorType, false) . '</div>';
        }

        return '';
    }

    /**
     * Ueberprueft ob das uebergebene Feld benoetigt wird um erfolgreich zu speichern.
     */
    public function isRequiredField(string $fieldName): string
    {
        if (array_intersect([$fieldName, $this->utils->getSpecialFieldKey($fieldName)], $this->arrRequiredFields)) {
            return '<span class="star">*</span>';
        }

        return '';
    }

    /**
     * Ueberprüft ob es für die uebergebene Tabelle eine andere Labelkonfiguration gibt.
     * Dieses LabelField wird dann benutzt, um für Listen Elmente das richtige Label zu holen.
     *
     * @param string $table
     * @return  string      $labelFieldName
     */
    public function getTableLabelFieldName($table)
    {
        if ($this->conf['tablelabelfield.'][$table]) {
            return $this->conf['tablelabelfield.'][$table];
        }

        return $GLOBALS['TCA'][$table]['ctrl']['label'];
    }

    /**
     * Erstellt GET-Parameter fuer vordefinierte Parameter die uebergeben wurden.
     *
     * @return  array       $arrParams
     */
    public function getHiddenParamsArray()
    {
        $arrParams = [];

        foreach ($this->arrHiddenParams as $paramName) {
            $arrParamNameParts = GeneralUtility::trimExplode('|', $paramName, true);

            $this->getParamArrayFromParamNameParts($arrParamNameParts, $_REQUEST, $arrParams);
        }

        return $arrParams;
    }

    /**
     * Erstellt Hidden Fields fuer vordefinierte Parameter die uebergeben wurden.
     *
     * @return  string      $content
     */
    public function getHiddenParamsHiddenFields(): string
    {
        $content = '';

        foreach ($this->arrHiddenParams as $paramName) {
            $arrParams = [];
            $arrParamNameParts = GeneralUtility::trimExplode('|', $paramName, true);

            $this->getParamArrayFromParamNameParts($arrParamNameParts, $_REQUEST, $arrParams);

            // Durchlaeuft das gesaeberte Array anhand der Pfad-Teile.
            while (count($arrParamNameParts) > 0) {
                $paramNamePart = array_shift($arrParamNameParts);

                // Abbrechen wenn der aktuelle Pfad-Teil nicht vorhanden ist wurde.
                if (!$arrParams[$paramNamePart]) {
                    break;
                }

                // Wenn der letzte Pfad-Teil erreicht ist, das Hidden Field ausgeben.
                if (!$arrParamNameParts) {
                    $arrParamNameParts = GeneralUtility::trimExplode('|', $paramName, true);

                    $hiddenFieldName = array_shift($arrParamNameParts);

                    if ($arrParamNameParts) {
                        $hiddenFieldName .= '[' . implode('][', $arrParamNameParts) . ']';
                    }

                    $content .= '<input type="hidden" name="' . $hiddenFieldName . '" value="' . htmlspecialchars((string)$arrParams[$paramNamePart]) . '" />';

                    break;
                }

                $arrParams = &$arrParams[$paramNamePart];
            }
        }

        return $content;
    }

    /**
     * Durchsucht ein mehrdimensionales Array mit dem uebergebenen Pfad-Array, und uebernimmt den gefundenen Wert gesaubert in ein neues mehrdimensionales Array.
     * Das Pfad-Array ist ein eindimensinales Array, dessen fortlaufende Werte die jeweilige Ebene im durchsuchten und geschriebenen Array repraesentieren!
     *
     * @param array $arrParamNameParts
     * @param array $arrRequest // Call by reference: Das Array in dem gesucht wird.
     * @param array $arrParams // Call by reference: Das Array in das der Pfad und der Wert geschrieben werden.
     */
    public function getParamArrayFromParamNameParts($arrParamNameParts, &$arrRequest, &$arrParams): void
    {
        while (count($arrParamNameParts) > 0) {
            $paramNamePart = array_shift($arrParamNameParts);

            // Abbrechen wenn der aktuelle Pfad-Teil nicht uebergeben wurde.
            if (!isset($arrRequest[$paramNamePart])) {
                break;
            }

            // Wenn der letzte Pfad-Teil erreicht ist, diesen uebertragen und saubern.
            if (!$arrParamNameParts) {
                $arrParams[$paramNamePart] = htmlspecialchars_decode((string)$arrRequest[$paramNamePart]);

                break;
            }

            // Wenn noch nicht der letzte Pfad-Teil erreicht ist, und der aktuelle Pfad-Teil ein Array ist, weiter machen!
            if ($arrParamNameParts && is_array($arrRequest[$paramNamePart])) {
                if (!isset($arrParams[$paramNamePart])) {
                    $arrParams[$paramNamePart] = [];
                }

                $arrParams = &$arrParams[$paramNamePart];
                $arrRequest = &$arrRequest[$paramNamePart];

                continue;
            }

            break;
        }
    }

    /**
     * Holt Konfigurationen aus der Flexform (Tab-bedingt) und ersetzt diese pro Konfiguration in der TypoScript Konfiguration.
     *
     * @global  $this ->conf
     * @global  $this ->extConf
     * @global  $this ->arrUsedFields
     * @global  $this ->arrUniqueFields
     * @global  $this ->arrRequiredFields
     * @global  $this ->arrHiddenParams
     */
    public function determineConfiguration(): void
    {
        $flexConf = [];

        // Extension Konfiguration ermitteln.
        $this->extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'][$this->extKey];

        // Alle Tabs der Flexformkonfiguration durchgehn.
        if (is_array($this->cObj->data['pi_flexform']['data'])) {
            foreach (array_keys($this->cObj->data['pi_flexform']['data']) as $tabKey) {
                $flexConf = $this->utils->getFlexformConfigurationFromTab($this->cObj->data['pi_flexform'], $tabKey, $flexConf);
            }
        }

        // Alle gesammelten Konfigurationen in $this->conf uebertragen.
        foreach ($flexConf as $key => $val) {
            if ($this->extConf['enableIrre'] && is_array($val)) {
                // Wenn IRRE Konfiguration uebergeben wurde und in der Extension Konfiguration gesetzt ist...
                $this->conf[$key] = $val;
            } else {
                // Alle anderen Konfigurationen...
                $this->conf = $this->utils->setFlexformConfigurationValue($key, $val, $this->conf);
            }
        }

        // Die IRRE Konfiguration abarbeiten.
        if ($this->extConf['enableIrre'] && $this->conf['databasefields']) {
            $this->determineIrreConfiguration();
        }

        // Konfigurationen, die an mehreren Stellen benoetigt werden, in globales Array schreiben.
        $this->arrUsedFields = GeneralUtility::trimExplode(',', $this->conf['usedfields'], true);
        $this->arrUniqueFields = array_unique(GeneralUtility::trimExplode(',', $this->conf['uniquefields'], true));
        $this->arrRequiredFields = array_unique(GeneralUtility::trimExplode(',', $this->conf['requiredfields'], true));
        $this->arrHiddenParams = array_unique(GeneralUtility::trimExplode(',', $this->conf['hiddenparams'], true));

        // Konfigurationen die immer gelten setzten (Feldnamen sind fuer konfigurierte Felder und fuer input Felder).
        $this->arrRequiredFields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyCaptcha);
        $this->arrRequiredFields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyPasswordconfirmation);
    }

    /**
     * Ueberschreibt eventuell vorhandene TypoScript Konfigurationen oder Flexform Konfigurationen mit den Konfigurationen aus IRRE.
     *
     * @global  $this ->conf
     */
    public function determineIrreConfiguration(): void
    {
        if (!is_array($this->conf['databasefields'])) {
            return;
        }

        $infoitems = 1;
        $fieldsets = 2;
        $userdeleteCounter = 0;
        $passwordconfirmationCounter = 0;
        $resendactivationCounter = 0;
        $captchaCounter = 0;
        $usedfields = [];
        $requiredfields = [];
        $uniquefields = [];

        $firstkey = key($this->conf['databasefields']);
        $locale = $this->getLocale();
        foreach ($this->conf['databasefields'] as $position => $field) {
            // Datenbankfelder abarbeiten.
            if ($field['field']) {
                $usedfields[] = $field['field'];

                // Requiredfields erweitern.
                if ($field['required']) {
                    $requiredfields[] = $field['field'];
                }

                // Uniquefields erweitern.
                if ($field['unique']) {
                    $uniquefields[] = $field['field'];
                }

                // Label setzten falls angegeben.
                if ($field['label']) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][$field['field']] = $field['label'];
                }
            }

            // Submit Button abarbeiten.
            if (isset($field[self::specialfieldKeySubmit])) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeySubmit);

                // Label setzten falls angegeben.
                if ($field[self::specialfieldKeySubmit]) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][self::specialfieldKeySubmit . '_' . $this->conf['showtype']] = $field[self::specialfieldKeySubmit];
                }
            }

            // Captcha Feld abarbeiten.
            if (isset($field[self::specialfieldKeyCaptcha]) && $captchaCounter < 1) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyCaptcha);

                // Requiredfields wird in "determineConfiguration" immer gesetzt!

                // Label setzten falls angegeben.
                if ($field[self::specialfieldKeyCaptcha]) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][self::specialfieldKeyCaptcha] = $field[self::specialfieldKeyCaptcha];
                }

                $captchaCounter++;
            }

            // Infoitems abarbeiten.
            if (isset($field[self::specialfieldKeyInfoitem])) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyInfoitem);

                // Falls in dem Feld etwas drinn steht.
                if ($field[self::specialfieldKeyInfoitem]) {
                    $this->conf['infoitems.'][$infoitems] = $field[self::specialfieldKeyInfoitem];
                }

                $infoitems++;
            }

            // Separators / Legends abarbeiten.
            if (isset($field[self::specialfieldKeySeparator])) {
                // Beim aller ersten Separator / Legend bloss die Legend setzten!
                if ($position == $firstkey) {
                    $this->conf['legends.']['1'] = $field[self::specialfieldKeySeparator];
                } else {
                    $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeySeparator);

                    // Falls in dem Feld etwas drinn steht.
                    if ($field[self::specialfieldKeySeparator]) {
                        $this->conf['legends.'][$fieldsets] = $field[self::specialfieldKeySeparator];
                    }

                    $fieldsets++;
                }
            }

            // Userdelete Checkbox abarbeiten.
            if (isset($field[self::specialfieldKeyUserdelete]) && $userdeleteCounter < 1) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyUserdelete);

                // Requiredfields erweitern.
                if ($field['required']) {
                    $requiredfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyUserdelete);
                }

                // Label setzten falls angegeben.
                if ($field[self::specialfieldKeyUserdelete]) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][self::specialfieldKeyUserdelete] = $field[self::specialfieldKeyUserdelete];
                }

                $userdeleteCounter++;
            }

            // Resendactivation Feld abarbeiten.
            if (isset($field[self::specialfieldKeyResendactivation]) && $resendactivationCounter < 1) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyResendactivation);

                // Requiredfields erweitern.
                if ($field['required']) {
                    $requiredfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyResendactivation);
                }

                // Label setzten falls angegeben.
                if ($field[self::specialfieldKeyResendactivation]) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][self::specialfieldKeyResendactivation] = $field[self::specialfieldKeyResendactivation];
                }

                $resendactivationCounter++;
            }

            // Passwordconfirmation Feld abarbeiten.
            if (isset($field[self::specialfieldKeyPasswordconfirmation]) && $passwordconfirmationCounter < 1) {
                $usedfields[] = $this->utils->getSpecialFieldKey(self::specialfieldKeyPasswordconfirmation);

                // Requiredfields wird in "determineConfiguration" immer gesetzt!

                // Label setzten falls angegeben.
                if ($field[self::specialfieldKeyPasswordconfirmation]) {
                    $this->conf['_LOCAL_LANG.'][$locale . '.'][self::specialfieldKeyPasswordconfirmation] = $field[self::specialfieldKeyPasswordconfirmation];
                }

                $passwordconfirmationCounter++;
            }
        }

        // In Konfiguration uebertragen.
        $this->conf['usedfields'] = implode(',', $usedfields);
        $this->conf['uniquefields'] = implode(',', $uniquefields);
        $this->conf['requiredfields'] = implode(',', $requiredfields);
    }

    /**
     * Ermittelt die komplette oder die uebergebene Unter-Konfiguration des aktuellen Anzeigetyps.
     */
    public function getConfigurationByShowtype(string $subConfig = ''): null|string|array
    {
        if (!$subConfig) {
            return $this->conf[$this->conf['showtype'] . '.'];
        }

        return $this->conf[$this->conf['showtype'] . '.'][$subConfig] ?? null;
    }

    /**
     * Gibt die komplette Validierungskonfiguration fuer die JavaScript Frontendvalidierung zurueck.
     *
     * @return  string      $configuration
     * @throws \Doctrine\DBAL\Exception
     */
    public function getJSValidationConfiguration(): string
    {
        // Hier eine fertig generierte Konfiguration:
        // datamints_feuser_config[11]=[];
        // datamints_feuser_config[11]["username"]=[];
        // datamints_feuser_config[11]["username"]["validation"]=[];
        // datamints_feuser_config[11]["username"]["validation"]["type"]="username";
        // datamints_feuser_config[11]["username"]["valid"]="Der Benutzername darf keine Leerzeichen beinhalten!";
        // datamints_feuser_config[11]["username"]["required"]="Es muss ein Benutzername eingegeben werden!";
        // datamints_feuser_config[11]["password"]=[];
        // datamints_feuser_config[11]["password"]["validation"]=[];
        // datamints_feuser_config[11]["password"]["validation"]["type"]="password";
        // datamints_feuser_config[11]["password"]["equal"]="Es muss zwei mal das gleiche Passwort eingegeben werden!";
        // datamints_feuser_config[11]["password"]["validation"]["size"]="6";
        // datamints_feuser_config[11]["password"]["size"]="Das Passwort muss mindestens 6 Zeichen lang sein!";
        // datamints_feuser_config[11]["password"]["required"]="Es muss ein Passwort angegeben werden!";
        // datamints_feuser_inputids[11] = new Array("tx_datamintsfeuser_pi1_username", "tx_datamintsfeuser_pi1_password", "tx_datamintsfeuser_pi1_password_rep");

        $arrValidationFields = [];
        $configuration = $this->extKey . '_config[' . $this->contentId . ']=[];';

        // Bei jedem Durchgang der Schleife wird die Konfiguration fuer ein Datenbankfeld geschrieben. Ausnahmen sind hierbei Passwordfelder.
        // Gleichzeitig werden die ID's der Felder in ein Array geschrieben und am Ende zusammen gesetzt "inputids".
        foreach ($this->arrUsedFields as $fieldName) {
            if (!(is_array($this->feUsersTca['columns'][$fieldName]) && is_array($this->conf['validate.'][$fieldName . '.'])) && !in_array($fieldName, $this->arrRequiredFields)) {
                continue;
            }

            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            $cleanedFieldName = $this->utils->getSpecialFieldName($fieldName);

            // Die Felder bei denen Aktionen statt finden sollen (Event Listener) ermitteln.
            $itemCount = 0;
            $itemIdSuffix = 'item';

            // Die Anzahl der Felder die ausgegeben wurden (falls mehrere Felder ausgegeben, also kein Select und nur mehr als eine Checkbox).
            if (
                $fieldConfig['type'] == 'radio'
                || ($fieldConfig['type'] == 'check' && count((array)$fieldConfig['items']) > 1)
                || ($fieldConfig['type'] == 'select' && $fieldConfig['renderMode'] == 'checkbox')
            ) {
                $itemCount = count((array)$fieldConfig['items']);
            }

            // Die Anzahl der Felder die ausgegeben wurden, wird beim Typ DB ueber einen Count auf die erlaubten Tabellen ermittelt.
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'db') {
                $arrAllowed = GeneralUtility::trimExplode(',', $fieldConfig['allowed'], true);

                foreach ($arrAllowed as $table) {
                    if (!$GLOBALS['TCA'][$table]) {
                        continue;
                    }


                    //$res = $this->databaseConnection->exec_SELECTquery('COUNT(*) as count', $table, '1 ' . $this->pageRepository->enableFields($table));
                    //$row = $this->databaseConnection->sql_fetch_assoc($res);

                    $count = $this->getRowCountFromTable($table);

                    $itemCount += $count;
                }
            }

            // Fuer den Typ File, gibt es noch einen alternativen item Id suffix!
            if ($fieldConfig['type'] == 'group' && $fieldConfig['internal_type'] == 'file') {
                $itemCount = $fieldConfig['size'];
                $itemIdSuffix = 'upload';
            }

            // Fuer den Typ Passwort, kommt das Passwort wiederholen Feld zum normalen Feld dazu!
            if ($this->conf['validate.'][$fieldName . '.']['type'] == 'password') {
                $arrValidationFields[] = $this->getFieldId($fieldName, 'rep');
            }

            // Gesammelte Items durchlaufen, ansonsten nur eine Feld ID zum Array der zu ueberpruefenden Felder hinzufuegen!
            if ($itemCount > 0) {
                for ($i = 1; $i <= $itemCount; $i++) {
                    $arrValidationFields[] = $this->getFieldId($fieldName, $itemIdSuffix, $i);
                }
            } else {
                $arrValidationFields[] = $this->getFieldId($cleanedFieldName);
            }

            // Fuer jedes Feld eine Konfiguration anlegen.
            $configuration .= $this->extKey . '_config[' . $this->contentId . ']["' . $cleanedFieldName . '"]=[];';

            // Validierungs Konfiguration ermitteln.
            if (is_array($this->conf['validate.'][$fieldName . '.'])) {
                $configuration .= $this->extKey . '_config[' . $this->contentId . ']["' . $fieldName . '"]["validation"]=[];';

                // Da es mehrere Validierungsoptionen pro Feld geben kann, muss hier jede einzeln durchgelaufen werden.
                foreach ($this->conf['validate.'][$fieldName . '.'] as $key => $val) {
                    $labelKey = self::validationerrorKeyValid;
                    $validationKey = $key;
                    $validationValue = '"' . str_replace('"', '\\"', $val) . '"';

                    switch ($key) {
                        case 'type':
                            if ($val == 'password') {
                                $labelKey = self::validationerrorKeyEqual;
                            }

                            break;

                        case 'length':
                            $labelKey = self::validationerrorKeyLength;

                            break;

                        case 'regexp':
                            $validationValue = $val;

                            break;
                    }

                    $configuration .= $this->extKey . '_config[' . $this->contentId . ']["' . $fieldName . '"]["validation"]["' . str_replace('length', 'size', $validationKey) . '"]=' . $validationValue . ';';

                    $configuration .= $this->extKey . '_config[' . $this->contentId . ']["' . $fieldName . '"]["' . str_replace('length', 'size', $labelKey) . '"]="' . str_replace('"', '\\"', $this->getLabel($fieldName . '_error_' . $labelKey, false)) . '";';
                }
            }

            // Required Konfiguration ermitteln.
            if (in_array($fieldName, $this->arrRequiredFields)) {
                $configuration .= $this->extKey . '_config[' . $this->contentId . ']["' . $cleanedFieldName . '"]["required"]="' . str_replace('"', '\\"', $this->getLabel($cleanedFieldName . '_error_' . self::validationerrorKeyRequired, false)) . '";';
            }
        }

        return $configuration . ($this->extKey . '_inputids[' . $this->contentId . ']=["' . implode('","', $arrValidationFields) . '"];');
    }

    protected function getLocale(): string
    {
        $site = $GLOBALS['TYPO3_REQUEST']->getAttribute('site');
        $context = GeneralUtility::makeInstance(Context::class);
        assert($context instanceof Context);
        $currentLanguageId = $context->getPropertyFromAspect('language', 'id');
        $language = $site->getLanguageById($currentLanguageId);
        assert($language instanceof SiteLanguage);
        return $language->getHreflang();
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function getRowCountFromTable(string $table): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->selectLiteral('COUNT(uid) as count')
            ->from($table);

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        return (int)$row['count'];
    }

    /**
     * @throws InvalidPasswordHashException
     */
    protected function hashPasswordInInputValues(array $arrUpdate): array
    {
        $updatedArray = [];
        foreach ($arrUpdate as $fieldName => $fieldValue) {
            $updatedArray[$fieldName] = $fieldValue;
            if (!isset($this->feUsersTca['columns'][$fieldName]['config'])) {
                continue;
            }
            $fieldConfig = $this->feUsersTca['columns'][$fieldName]['config'];
            if ($fieldConfig['type'] !== 'password') {
                continue;
            }

            $updatedArray[$fieldName] = $this->hashPassword($fieldValue);
        }
        return $updatedArray;
    }
}
