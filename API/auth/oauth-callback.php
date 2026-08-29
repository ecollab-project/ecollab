<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/OAuthService.php';

AuthMiddleware::startSession();
$provider=strtolower(trim($_GET['provider']??'')); $code=trim($_GET['code']??''); $state=trim($_GET['state']??''); $error=trim($_GET['error']??'');
if($error!==''){$msg=$error==='access_denied'?'You cancelled the '.ucfirst($provider).' sign-in.':'SSO sign-in could not be completed.';header('Location: '.BASE_URL.'/modules/auth/login.php?sso_error='.urlencode($msg));exit;}
if(!in_array($provider,['google','microsoft'],true)||$code===''){header('Location: '.BASE_URL.'/modules/auth/login.php?sso_error='.urlencode('Invalid callback parameters. Please try signing in again.'));exit;}
try{$result=(new OAuthService())->handleCallback($provider,$code,$state);}catch(Throwable $e){error_log('[auth/oauth-callback] '.$e->getMessage());$result=['success'=>false,'error'=>'SSO sign-in could not be completed. Please try again.'];}
if(!$result['success']){header('Location: '.BASE_URL.'/modules/auth/login.php?sso_error='.urlencode('SSO sign-in could not be completed. Please try again.'));exit;}
header('Location: '.$result['redirect']);exit;
