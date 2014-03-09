<?php

/*
 * This file is part of the Studio Fact package.
 *
 * (c) Kulichkin Denis (onEXHovia) <onexhovia@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Entity;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Event;

global $APPLICATION, $USER_FIELD_MANAGER;

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('highloadblock')) {
	throw new LoaderException(sprintf('Module "Highloadblock" not set'));
}

$application = Application::getInstance();
// Instance for old application
$applicationOld = &$APPLICATION;

$isAjax = (getenv('HTTP_X_REQUESTED_WITH') == 'XMLHttpRequest') ?: false;
$componentId = CAjax::GetComponentID($this->getName(), $this->getTemplateName());
$request = $application->getContext()->getRequest();
$componentAjax = false;

// If after adding a redirect occurred at the same page 
if (array_key_exists(sprintf('feedback_success_%s', $componentId), $_SESSION)) {
	unset($_SESSION[sprintf('feedback_success_%s', $componentId)]);
	$success = true;
}

// For the current component ajax request?
if ($isAjax) {
	$componentAjax = function() use($componentId, $request) {
		if (!$request->isPost() || !$request->getPost('ajax_id')) {
			return false;
		}
		
		return ($request->getPost('ajax_id') == $componentId);
	};
}

// Return new code for captcha
if ($arParams['USE_CAPTCHA'] == 'Y' && $request->getPost('feedback_captcha_remote') && $componentAjax) {
	$applicationOld->RestartBuffer();
	header('Content-Type: application/json');
	exit(json_encode(array('captcha' => $applicationOld->CaptchaGetCode())));
}

// Checking highload block
$hlblock = HL\HighloadBlockTable::getById($arParams['HLBLOCK_ID'])->fetch();
if (empty($hlblock)) {
	throw new ConfigurationException(sprintf('Highloadblock with ID = %d not found', $arParams['HLBLOCK_ID']));
}

$entityBase = HL\HighloadBlockTable::compileEntity($hlblock);
$entityBaseFields = $USER_FIELD_MANAGER->GetUserFields(sprintf('HLBLOCK_%d', $hlblock['ID']), 0, LANGUAGE_ID);

// Validatation data in a form
if ($request->isPost() && $request->getPost(sprintf('send_form_%s', $componentId))) {
	$postData = $request->getPostList()->toArray();
	$postData = array_map('strip_tags', $postData);

	if ($arParams['USE_CAPTCHA'] == 'Y') {
		if (!$applicationOld->CaptchaCheckCode($postData['captcha_word'], $postData['captcha_sid'])) {
			$errorList['captcha_word'] = Loc::getMessage('ERROR_CAPTCHA');
		}
	}

	$postData = array_intersect_key($postData, $entityBaseFields);
	$USER_FIELD_MANAGER->EditFormAddFields(sprintf('HLBLOCK_%d', $hlblock['ID']), $postData);

	if (!$USER_FIELD_MANAGER->CheckFields(sprintf('HLBLOCK_%d', $hlblock['ID']), $postData)) {
		$errorList['internal'] = $applicationOld->GetException()->GetString();
	}
	
	if (!isset($errorList)) {
		$enityData = $entityBase->getDataClass();
		$result = $enityData::add($postData);
	
		$success = ($result->isSuccess()) ? true : false;
		$internal = ($success === false) ? true : false;
		
		if (!$success) {
			$errorList = $result->getErrorMessages();
			$errorList = (sizeof($errorList) == 1) ? explode('<br>', $errorList[0]) : $errorList;

			foreach ($entityBaseFields as $name => $field) {
				foreach ($errorList as $key => $error) {
					if (preg_match('#'.$field['EDIT_FORM_LABEL'].'#', $error) || $field['ERROR_MESSAGE'] == $error) {
						if (!array_key_exists($name, $errorList)) {
							$errorList[$name] = $error;
						}

						unset($errorList[$key]);
					}
				}
			}

			// Remove empty cell
			$errorList = array_diff($errorList, array(null));
		} else {
			// Adding a post event
			$event = $arParams['EVENT_NAME'];
			$eventTemplate = (is_numeric($arParams['EVENT_TEMPLATE'])) ?: '';

			$eventType = CEventType::GetList(array('EVENT_NAME' => $event))->GetNext();
			if ($event && is_array($eventType)) {
				CEvent::send($event, SITE_ID, $postData, 'Y', $eventTemplate);
			}
		}

		if (strlen($arParams['REDIRECT_PATH']) > 0 && $success && $arParams['AJAX'] != 'Y') {
			LocalRedirect($arParams['REDIRECT_PATH']);
		} elseif ($success && $arParams['AJAX'] != 'Y') {
			$redirectPath = $application
				->getContext()
				->getServer()
				->getRequestUri();

			$_SESSION[sprintf('feedback_success_%s', $componentId)] = true;
			LocalRedirect($redirectPath);
		}
	}
	
	// If enabled ajax mod and action in the request feedback_remote
	// Return the validation form in the format json
	if ($arParams['AJAX'] == 'Y' && $request->getPost('feedback_remote') && $componentAjax) {
		$applicationOld->RestartBuffer();
		header('Content-Type: application/json');
		
		$jsonResponse = array(
			'success' => $success ?: false,
			'errors' => $errorList ?: array(),
			'internal' => $internal ?: false,
			'use_redirect' => (strlen($arParams['REDIRECT_PATH']) > 0) ?: false,
			'redirect_path' => $arParams['REDIRECT_PATH'],
		);
		
		if ($arParams['USE_CAPTCHA'] == 'Y') {
			$jsonResponse['captcha'] = $applicationOld->CaptchaGetCode();
		}

		exit(json_encode($jsonResponse));
	}
}

$postData = array_map('htmlspecialchars', (isset($postData)) ? $postData : array());
$arResult = array(
	'IS_POST' => $request->isPost(),
	'SUCCESS' => $success ?: false,
	'INTERNAL' => $internal ?: false,
	'ERRORS' => $errorList ?: array(),
	'HLBLOCK' => array(
		'DATA' => $hlblock,
		'FIELDS' => $entityBaseFields,
	)
);

$arResult['FORM']['COMPONENT_ID'] = $componentId;
foreach ($entityBaseFields as $name => $value) {
	$arResult['FORM'][$name] = array_key_exists($name, $postData) ? $postData[$name] : '';
}

if ($arParams['USE_CAPTCHA'] == 'Y') {
	$arResult['CAPTCHA_CODE'] = $applicationOld->CaptchaGetCode();
	$arResult['FORM']['CAPTCHA'] = array_key_exists('captcha_word', $postData) ? $postData['name'] : '';
}

$this->IncludeComponentTemplate();