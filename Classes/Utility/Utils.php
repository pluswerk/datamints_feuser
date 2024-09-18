<?php

declare(strict_types=1);

namespace Datamints\Feuser\Utility;

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
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Service\MarkerBasedTemplateService;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class Utils implements SingletonInterface
{
	/**
	 * Ueberschreibt eventuell vorhandene TCA Konfiguration mit TypoScript Konfiguration.
	 *
	 * @param array $feUsersTca
	 * @return  array       $globalFeUsersTca
	 */
	public function getFeUsersTca($feUsersTca)
	{
		$globalFeUsersTca = $GLOBALS['TCA']['fe_users'];

		if ($feUsersTca) {
			$columns = (array)$globalFeUsersTca['columns'];

			ArrayUtility::mergeRecursiveWithOverrule($columns, GeneralUtility::removeDotsFromTS($feUsersTca));

			$globalFeUsersTca['columns'] = $columns;
		}

		return $globalFeUsersTca;
	}

	/**
	 * Ermittelt das Language Field einer Tabelle.
	 *
	 * @param string $table
	 * @return  string
	 */
	public function getLanguageFieldName($table)
	{
		if (array_key_exists($table, $GLOBALS['TCA']) && array_key_exists('languageField', $GLOBALS['TCA'][$table]['ctrl'])) {
			return $GLOBALS['TCA'][$table]['ctrl']['languageField'];
		}

		return '';
	}

	/**
	 * Ermittelt die General Record Storage Pid, falls keine Pid uebergeben wurde.
	 *
	 * @param integer $storagePageId
	 * @return  integer     $storagePid
	 */
	public function getStoragePageId($storagePageId): int
	{
		if ($storagePageId) {
			return intval($storagePageId);
		}

		foreach ((array)$GLOBALS['TSFE']->rootLine as $page) {
			$storagePageId = intval($page['storage_pid']);

			if ($storagePageId) {
				return $storagePageId;
			}
		}

		return 0;
	}

	/**
	 * Ermittelt die Url zu einer Seite oder einer Datei.
	 *
	 * @param string $params
	 * @param array $urlParameters
	 * @return  string      $pageLink
	 */
	public function getTypoLinkUrl($params, $urlParameters = [])
	{
		$cObj = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\ContentObject\\ContentObjectRenderer');

		return $cObj->getTypoLink_URL($params, $urlParameters);
	}

	/**
	 * Fuehrt einen stdWrap mit den aktuellen Benutzerdaten aus.
	 *
	 * @param string $content
	 * @param array $stdWrap
	 * @return  string      $content
	 */
	public function currentUserWrap($content, $stdWrap)
	{
		$cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
		assert($cObj instanceof ContentObjectRenderer);
		$cObj->data = $GLOBALS['TSFE']->fe_user->user;

		return $cObj->stdWrap($content, $stdWrap);
	}

	/**
	 * Wird verwendet, um doppelte Schraegstriche zu vermeiden.
	 * Der Pfad wird mit einem abschliessenden Schraegstrich zurueckgegeben.
	 *
	 * @return  string      $path
	 */
	public function fixPath(string $path): string
	{
		return dirname($path . '/.') . '/';
	}

	/**
	 * Konvertiert alle Werte des uebergebenen Post Arrays um z.B. XSS zu verhindern.
	 * Der Modus gibt an ob die Werte encodiert oder decodiert werden soll.
	 *
	 * @param array $arrPost // Call by reference: Das Post Array dessen Werte konvertiert werden.
	 * @param boolean $decode
	 */
	public function htmlspecialcharsPostArray(&$arrPost, $decode): bool
	{
		if ($decode) {
			// Konvertiert alle moeglichen Zeichen die fuer die Ausgabe angepasst wurden zurueck.
			foreach ($arrPost as $key => $val) {
				if (!is_array($arrPost[$key])) {
					$arrPost[$key] = htmlspecialchars_decode((string)$val);
				}
			}
		} else {
			// Konvertiert alle moeglichen Zeichen der Ausgabe, die stoeren koennten (XSS).
			foreach ($arrPost as $key => $val) {
				// Falls es kein Array ist, darf auch HTML enthalten sein, deshalb nur htmlspecialchars() anwenden!
				if (!is_array($arrPost[$key])) {
					$arrPost[$key] = htmlspecialchars((string)$val);
				} else {
					// Wenn es ein Array ist, dann auf alle Elemente strip_tags() anwenden!
					array_walk_recursive($arrPost[$key], 'tx_datamintsfeuser_utils::stripTagsCallback');
				}
			}
		}

		return true;
	}

	/**
	 * Es wird jeder Wert im Post Array ueberprueft ob er ein Array ist.
	 * Wenn dass der Fall ist, wird der erste Wert in diesem Array entfernt, falls dieser ein Leerstring ist.
	 *
	 * @param array $arrPost // Call by reference: Das Post Array
	 */
	public function shiftEmptyArrayValuePostArray(array &$arrPost): bool
	{
		foreach ($arrPost as $key => $value) {
			if (is_array($value) && $value[0] === '') {
				unset($value[0]);

				$arrPost[$key] = $value;
			}
		}

		return true;
	}

	/**
	 * Erstellt wenn gefordert ein Password, und verschluesselt dieses, oder das uebergebene, wenn es verschluesselt werden soll.
	 *
	 * @param string $password
	 * @return  array       $arrPassword
	 */
	public function generatePassword($password, array $arrGenerate = []): array
	{
		$arrPassword = [];

		// Uebergebenes Password setzten.
		// Hier wird kein strip_tags() o.Ae. benoetigt, da beim schreiben in die Datenbank immer "$GLOBALS['TYPO3_DB']->fullQuoteStr()" ausgefuehrt wird!
		$arrPassword['normal'] = trim($password);

		// Erstellt ein Password.
		if ($arrGenerate['mode']) {
			$chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHIKLMNPQRSTUVWXYZ';

			$arrPassword['normal'] = '';

			for ($i = 0; $i < ($arrGenerate['length'] ?: 8); $i++) {
				$arrPassword['normal'] .= $chars[mt_rand(0, strlen($chars))];
			}
		}

		// Unverschluesseltes Passwort uebertragen.
		$arrPassword['encrypted'] = $arrPassword['normal'];

		//// Wenn "saltedpasswords" installiert ist wird deren Konfiguration geholt, und je nach Einstellung das Password verschluesselt.
		//if (ExtensionManagementUtility::isLoaded('saltedpasswords') && $GLOBALS['TYPO3_CONF_VARS']['FE']['loginSecurityLevel']) {
		//	$saltedpasswords = SaltedPasswordsUtility\::returnExtConf();
//
		//	if ($saltedpasswords['enabled']) {
		//		$tx_saltedpasswords = GeneralUtility::makeInstance($saltedpasswords['saltedPWHashingMethod']);
//
		//		$arrPassword['encrypted'] = $tx_saltedpasswords->getHashedPassword($arrPassword['normal']);
		//	}
		//}

		if ($GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing']['className']) {
			$arrPassword['encrypted'] = GeneralUtility::makeInstance(PasswordHashFactory::class)->getDefaultHashInstance('FE')->getHashedPassword($arrPassword['normal']);
		}

		return $arrPassword;
	}

	/**
	 * Ueberprueft anhand der aktuellen Verschluesselungsextension, ob das uebergebene unverschluesselte Passwort mit dem uebergebenen verschluesselten Passwort uebereinstimmt.
	 *
	 * @return  boolean     $check
	 */
	public function checkPassword(string $submittedPassword, string $originalPassword)
	{
		$check = false;

		// Wenn "saltedpasswords" installiert ist wird deren Konfiguration geholt, und je nach Einstellung das Password ueberprueft.
		if (ExtensionManagementUtility::isLoaded('saltedpasswords') && $GLOBALS['TYPO3_CONF_VARS']['FE']['loginSecurityLevel']) {
			$saltedpasswords = SaltedPasswordsUtility::returnExtConf();

			if ($saltedpasswords['enabled']) {
				$tx_saltedpasswords = GeneralUtility::makeInstance($saltedpasswords['saltedPWHashingMethod']);

				$check = $tx_saltedpasswords->checkPassword($submittedPassword, $originalPassword);
			}
		}

		if ($GLOBALS['TYPO3_CONF_VARS']['FE']['passwordHashing']['className']) {
			return GeneralUtility::makeInstance(PasswordHashFactory::class)->get($originalPassword, 'FE')->checkPassword($submittedPassword, $originalPassword);
		}

		return $check;
	}

	/**
	 * Vollzieht einen Login ohne ein Passwort.
	 *
	 * @param integer $userId
	 * @param integer $pageId
	 * @param array $urlParameters
	 */
	public function userAutoLogin($userId, $pageId = 0, $urlParameters = []): void
	{
		// Login vollziehen.
		$GLOBALS['TSFE']->fe_user->checkPid = 0;

		$userRecord = $GLOBALS['TSFE']->fe_user->getRawUserByUid($userId);

		$GLOBALS['TSFE']->fe_user->createUserSession($userRecord);

		// Session erzwingen um einen FE Cookie zu bekommen (TYPO3 6.2.5+, see https://forge.typo3.org/issues/62194).
		$setSessionCookieMethod = new ReflectionMethod($GLOBALS['TSFE']->fe_user, 'setSessionCookie');
		$setSessionCookieMethod->setAccessible(true);
		$setSessionCookieMethod->invoke($GLOBALS['TSFE']->fe_user);

		// Umleiten, damit der Login wirksam wird.
		$this->userRedirect($pageId, $urlParameters, true);
	}

	/**
	 * Vollzieht einen Redirect mit der Seite die benutzt wird, oder auf die aktuelle.
	 *
	 * @param integer $pageId
	 * @param array $urlParameters
	 * @param boolean $disableAccessCheck
	 */
	public function userRedirect($pageId = 0, $urlParameters = [], $disableAccessCheck = false): void
	{
		// Normalen Redirect, oder Redirect auf die gewuenschte Seite.
		if (!$pageId) {
			$pageId = $GLOBALS['TSFE']->id;
		}

		// Damit man auch auf Seiten die erst nach dem Login sichtbar sind umleiten kann, wird hier die Gruppen Zugangsüberprüfung vorrübergehend deaktiviert.
		// Das wird aber nur bei einem Autologin benötigt, da sich nur dort der Status des Users während des Abarbeitungsprozesses ändert.
		// WICHTIG: Falls nach dem Login die Seite immer noch unsichtbar (nicht zugänglich) ist, greift die normale Typo3 Umleitung.
		if ($disableAccessCheck) {
			$GLOBALS['TSFE']->config['config']['typolinkLinkAccessRestrictedPages'] = 'NONE';
		}

		$pageLink = $this->getTypoLinkUrl($pageId, $urlParameters);

		header('Location: ' . GeneralUtility::locationHeaderUrl($pageLink));
		exit;
	}

	/**
	 * URL encoded die eckigen Klammern in einem Link.
	 *
	 * @param string $url
	 */
	public function escapeBrackets($url): string
	{
		$replace = ['[' => '%5b', ']' => '%5d'];

		return str_replace(array_keys($replace), array_values($replace), $url);
	}

	/**
	 * Fuegt die '--' Zeichen vor und hinter dem eigendlichen Feldnamen, hinzu um den eindeutigen Key zu bekommen.
	 */
	public function getSpecialFieldKey(string $fieldName): string
	{
		return '--' . $fieldName . '--';
	}

	/**
	 * Ersetzt die beim Eingeben angegebenen '--' Zeichen vor und hinter dem eigendlichen Feldnamen, falls vorhanden.
	 *
	 * @param string $fieldName
	 * @return  string
	 */
	public function getSpecialFieldName($fieldName)
	{
		if (preg_match('/^--.*--$/', $fieldName)) {
			return preg_replace('/^--(.*)--$/', '\1', $fieldName);
		}

		return $fieldName;
	}

	/**
	 * Convertiert eine HTML E-Mail zu einer Plain Text E-Mail.
	 *
	 * @param string $content
	 * @return  string      $content
	 */
	public function convertHtmlEmailToPlain($content): string|array|null
	{
		$newLine = chr(13) . chr(10);

		// Den Head entfernen.
		$content = preg_replace('/<head>.*?<\/head>/s', '', $content, 1);

		// Links auflösen (A-Tag entfernen und Href extrahieren).
		$content = preg_replace('/<a[^>]*href="([^"]*)"[^>]*>[^<]*<\/a>/i', ' $1 ', $content);

		// Nach jedem schliessenden Tag eine Leerzeile einfuegen.
		$content = preg_replace('/>/i', '>' . $newLine, $content);

		// HTML Sonderzeichen in Textzeichen umwandeln.
		$content = html_entity_decode((string)$content);

		// Alle HTML Tags entfernen und allgemein trimmen.
		$content = trim(strip_tags($content));

		// Jede Zeile trimmen.
		$arrContent = preg_split('/\r?\n/', $content);

		foreach ($arrContent as $key => $val) {
			$arrContent[$key] = trim($val);
		}

		$content = implode($newLine, $arrContent);

		// Wenn mehr als 2 Zeilenumbrueche hintereinander kommen, 2 daraus machen.
		$content = preg_replace('/(' . $newLine . '){2,}/', $newLine . $newLine, $content);

		return $content;
	}

	/**
	 * Holt einen Subpart des Standardtemplates und ersetzt uebergeben Marker.
	 *
	 * @param string $templateFile
	 * @param string $templatePart
	 * @param array $markerArray
	 * @return  string      $template
	 */
	public function getTemplateSubpart($templateFile, $templatePart, $markerArray = [])
	{
		// Template laden.
		$resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
		$fileFolder = $resourceFactory->retrieveFileOrFolderObject($templateFile);

		if (!$fileFolder && class_exists(LinkService::class)) {
			$result = GeneralUtility::makeInstance(LinkService::class)->resolve($templateFile);

			$fileFolder = $result['file'] ?? null;
		}

		$template = ($fileFolder instanceof File) ? $fileFolder->getContents() : false;

		$templateService = GeneralUtility::makeInstance(class_exists(MarkerBasedTemplateService::class) ? MarkerBasedTemplateService::class : 'TYPO3\\CMS\\Frontend\\ContentObject\\ContentObjectRenderer');
		$template = $templateService->getSubpart($template, '###' . strtoupper($templatePart) . '###');

		//      if (!$this->checkUtf8($template)) {
		//          $template = utf8_encode ($template);
		//      }

		$template = $templateService->substituteMarkerArray($template, $markerArray, '###|###', true);

		return $template;
	}

	/**
	 * Parst das Flexform Konfigurations Array und schreibt alle Werte in $conf.
	 *
	 * @param array $flexData
	 * @param string $sTab
	 * @param array $conf
	 * @return  array       $conf
	 */
	public function getFlexformConfigurationFromTab($flexData, $sTab, $conf = [])
	{
		if (isset($flexData['data'][$sTab]['lDEF'])) {
			$flexData = $flexData['data'][$sTab]['lDEF'];
		}

		if (!is_array($flexData)) {
			return $conf;
		}

		foreach ($flexData as $key => $value) {
			if (!is_array($value)) {
				continue;
			}

			if (is_array($value['el']) && count($value['el']) > 0) {
				foreach ($value['el'] as $ekey => $element) {
					if (!is_array($element)) {
						continue;
					}

					if (isset($element['vDEF'])) {
						$conf[$ekey] = $element['vDEF'];
					} else {
						$conf[$key][$ekey] = $this->getFlexformConfigurationFromTab($element, $sTab, $conf[$key][$ekey]);
					}
				}
			} else {
				$conf = $this->getFlexformConfigurationFromTab($value['el'], $sTab, $conf);
			}

			if ($value['vDEF']) {
				$conf[$key] = $value['vDEF'];
			}
		}

		return $conf;
	}

	/**
	 * Ueberschreibt eventuell vorhandene TypoScript Konfigurationen mit den Konfigurationen aus der Flexform.
	 *
	 * @param string $key
	 * @param string $value
	 * @return  array       $conf
	 */
	public function setFlexformConfigurationValue($key, $value, array $conf)
	{
		if (str_contains($key, '.') && $value) {
			$arrKey = GeneralUtility::trimExplode('.', $key, true);
			for ($i = count($arrKey) - 1; $i >= 0; $i--) {
				$newValue = [];

				if ($i == count($arrKey) - 1) {
					$newValue[$arrKey[$i]] = $value;
				} else {
					$newValue[$arrKey[$i] . '.'] = $value;
				}

				$value = $newValue;
			}

			ArrayUtility::mergeRecursiveWithOverrule($conf, $value);
		} elseif ($value) {
			$conf[$key] = $value;
		}

		return $conf;
	}

	/**
	 * Nimmt einen String entgegen um auf diesen ein trim() anzuwenden.
	 *
	 * @param string $string // Call by reference: Der String der getrimmt wird.
	 */
	public function trimCallback(&$string): void
	{
		$string = trim($string);
	}

	/**
	 * Nimmt einen String entgegen um auf diesen ein strip_tags() anzuwenden.
	 *
	 * @param string $string // Call by reference: Der String der gesaubert wird.
	 */
	public function stripTagsCallback(&$string): void
	{
		$string = strip_tags($string);
	}

	/**
	 * Checks if a string is utf8 encoded or not.
	 *
	 * @param string $str
	 */
	public function checkUtf8($str): bool
	{
		$len = strlen($str);
		for ($i = 0; $i < $len; $i++) {
			$c = ord($str[$i]);

			if ($c > 128) {
				if ($c > 247) {
					return false;
				}

				if ($c > 239) {
					$bytes = 4;
				} elseif ($c > 223) {
					$bytes = 3;
				} elseif ($c > 191) {
					$bytes = 2;
				} else {
					return false;
				}

				if (($i + $bytes) > $len) {
					return false;
				}

				while ($bytes > 1) {
					$i++;
					$b = ord($str[$i]);

					if ($b < 128 || $b > 191) {
						return false;
					}

					$bytes--;
				}
			}
		}

		return true;
	}
}

