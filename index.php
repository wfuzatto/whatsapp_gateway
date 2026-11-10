<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$route = (string)($_GET['r'] ?? '');
if (preg_match('#^api/v1/(contacts|messages|templates|media(?:/\d+)?)$#',$route,$apiMatch)) { handle_api($apiMatch[1]); exit; }
if (str_starts_with($route, 'api/')) { handle_api(substr($route, 4)); exit; }
if ($route === 'webhook') { handle_meta_webhook(); exit; }
if (!installed()) { require __DIR__ . '/app/setup_wizard.php'; exit; }
if ($route === 'logout') { session_destroy(); redirect('?r=login'); }
if (!current_user() && $route !== 'login') redirect('?r=login');
if ($route === 'login') { handle_login(); exit; }
if (current_user() && in_array(current_user()['role'],['platform_admin','owner','admin'],true) && empty(current_user()['mfa_enabled']) && $route !== 'settings') redirect('?r=settings&mfa=required');
if ($route === 'media') { handle_media(); exit; }
handle_action();
render_app();
