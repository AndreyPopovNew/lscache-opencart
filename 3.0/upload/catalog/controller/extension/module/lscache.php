<?php

/*
 *  @since      1.0.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */

class ControllerExtensionModuleLSCache extends Controller
{

    const LOG_ERROR = 3;
    const LOG_INFO = 6;
    const LOG_DEBUG = 8;

    public function onAfterInitialize($route, &$args)
    {

        //$this->log('init:' . $route . PHP_EOL, self::LOG_DEBUG);

        if (($this->lscache == null) || (!isset($this->cache->cacheEnabled))) {
            //pass
        } else if ($route == "extension/module/lscache/renderESI") {
            return; //ESI render
        } else if ($this->lscache->pageCachable) {
            return;
        } else if ($this->lscache->cacheEnabled) {
            $this->onAfterRoute($route, $args);
            return;
        } else {
            return;
        }

        $this->lscache =  (object) array('route' => $route, 'setting' => null, 'cacheEnabled' => false, 'pageCachable' => false, 'urlRule'=>false, 'esiEnabled' => false, 'esiOn' => false,  'cacheTags'=> array(), 'lscInstance'=> null, 'pages'=> null, 'includeUrls'=> null, 'includeSorts'=> null, 'includeFilters'=> null  );

        $this->load->model('extension/module/lscache');
        $this->lscache->setting = $this->model_extension_module_lscache->getItems();
        $this->lscache->pages = $this->model_extension_module_lscache->getPages();

        if (isset($this->lscache->setting['module_lscache_status']) && (!$this->lscache->setting['module_lscache_status'])) {
            return;
        }

        // Server type
        if (!defined('LITESPEED_SERVER_TYPE')) {
            if (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ADC');
            } elseif (isset($_SERVER['LSWS_EDITION']) && ( strpos($_SERVER['LSWS_EDITION'], 'Openlitespeed') !== FALSE )) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_OLS');
            } elseif (isset($_SERVER['SERVER_SOFTWARE']) && $_SERVER['SERVER_SOFTWARE'] == 'LiteSpeed') {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ENT');
            } else {
                define('LITESPEED_SERVER_TYPE', 'NONE');
            }
        }

        // Checks if caching is allowed via server variable
        if (!empty($_SERVER['X-LSCACHE']) || LITESPEED_SERVER_TYPE === 'LITESPEED_SERVER_ADC' || defined('LITESPEED_CLI')) {
            !defined('LITESPEED_ALLOWED') && define('LITESPEED_ALLOWED', true);
            $this->lscache->cacheEnabled = true;
        } else {
            $this->log->write('server type:' . LITESPEED_SERVER_TYPE);
            $this->log->write('lscache not enabled');
            return;
        }

        if (( LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ) && isset($this->lscache->setting['module_lscache_esi']) && ($this->lscache->setting['module_lscache_esi'] == '1')) {
            $this->lscache->esiEnabled = true;
        }

        include_once(DIR_SYSTEM . 'library/lscache/lscachebase.php');
        include_once(DIR_SYSTEM . 'library/lscache/lscachecore.php');
        $this->lscache->lscInstance = new LiteSpeedCacheCore();
        $this->lscache->lscInstance->setHeaderFunction($this->response, 'addHeader');

        if ((isset($_SERVER['HTTP_USER_AGENT'])) && (($_SERVER['HTTP_USER_AGENT'] == 'lscache_runner') || ($_SERVER['HTTP_USER_AGENT'] == 'lscache_walker'))) {
            $recache = 0;
            if (isset($this->lscache->setting['recache_options'])) {
                $recache = $this->lscache->setting['recache_options'];
            }

            if (isset($_COOKIE['language']) && (($recache == 1) || ($recache == 3))) {
                $this->session->data['language'] = $_COOKIE['language'];
            }

            if (isset($_COOKIE['currency']) && (($recache == 2) || ($recache == 3))) {
                $this->session->data['currency'] = $_COOKIE['currency'];
            }
        }

        $includeUrls = isset($this->lscache->setting['module_lscache_include_urls']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_include_urls']) : null;
        $this->lscache->includeUrls=$includeUrls;
        
        // additional sorts and filters to recache_options
        $includeSorts = isset($this->lscache->setting['module_lscache_include_sorts']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_include_sorts']) : null;
        $this->lscache->includeSorts=$includeSorts;
        $includeFilters = isset($this->lscache->setting['module_lscache_include_filters']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_include_filters']) : null;
        $this->lscache->includeFilters=$includeFilters;

        
        $excludeLoginUrls = isset($this->lscache->setting['module_lscache_exclude_login_urls']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_exclude_login_urls']) : null;
        $excludeUrls = isset($this->lscache->setting['module_lscache_exclude_urls']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_exclude_urls']) : null;
        $uri = trim($_SERVER['REQUEST_URI']);

        if ($includeUrls && in_array($uri, $includeUrls)) {
            $this->lscache->pageCachable = true;
            $this->lscache->urlRule = true;
        }

        if ($this->customer->isLogged() && $excludeLoginUrls && in_array($uri, $excludeLoginUrls)) {
            $this->lscache->pageCachable = false;
            $this->lscache->urlRule = true;
        }

        if ($excludeUrls && in_array($uri, $excludeUrls)) {
            $this->lscache->pageCachable = false;
            $this->lscache->urlRule = true;
        }

        if ($route != "extension/module/lscache/renderESI") {
            $this->onAfterRoute($route, $args);
        }
    }

    public function onAfterRoute($route, &$args)
    {
        if (!$this->lscache->pageCachable && !$this->lscache->urlRule) {
            $pageKey = 'page_' . str_replace('/', '_', $route);
            if (isset($this->lscache->pages[$pageKey])) {
                $pageSetting = $this->lscache->pages[$pageKey];
            } else {
                return;
            }

            if ($this->customer->isLogged()) {
                if ($pageSetting['cacheLogin']) {
                    $this->lscache->pageCachable = true;
                } else {
                    return;
                }
            } else if ($pageSetting['cacheLogout']) {
                $this->lscache->pageCachable = true;
            } else {
                return;
            }

            $this->lscache->cacheTags[] = $pageKey;
        }

        //$this->log('route:' . $route . PHP_EOL , self::LOG_DEBUG); //comment to prevent strange error in log

        $this->event->unregister('controller/*/before', 'extension/module/lscache/onAfterInitialize');
        $this->event->register('controller/' . $route . '/after', new Action('extension/module/lscache/onAfterRender'));

        //$this->log('page cachable:' . $this->lscache->pageCachable);

        if ($this->lscache->esiEnabled) {
            $esiModules = $this->model_extension_module_lscache->getESIModules();
            $route = "";
            foreach ($esiModules as $key => $module) {
                if ($module['route'] != $route) {
                    $route = $module['route'];
                    $this->event->register('controller/' . $route . '/after', new Action('extension/module/lscache/onAfterRenderModule'));
                }
            }
            $this->event->register('model/setting/module/getModule', new Action('extension/module/lscache/onAfterGetModule'));
        }
    }

    public function onAfterRenderModule($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->pageCachable)) {
            return;
        }

        $esiModules = $this->model_extension_module_lscache->getESIModules();
        $esiKey = 'esi_' . str_replace('/', '_', $route);
        if (count($args) > 0) {
            $esiKey .= '_' . $args['module_id'];
        }
        if (!isset($esiModules[$esiKey])) {
            return;
        }

        $module = $esiModules[$esiKey];
        $esiType = $module['esi_type'];

        $link = $this->url->link('extension/module/lscache/renderESI', '');
        $link .= '&esiRoute=' . $route;
        if (isset($module['module']) && ($module['name'] != $module['module'])) {
            $link .= '&module_id=' . $module['module'];
        }

        if ($esiType == 3) {
            $esiBlock = '<esi:include src="' . $link . '" cache-control="public"/>';
        } else if ($esiType == 2) {
            if ($this->emptySession()) {
                return;
            }
            $esiBlock = '<esi:include src="' . $link . '" cache-control="private"/>';
        } else if ($esiType == 1) {
            $esiBlock = '<esi:include src="' . $link . '" cache-control="no-cache"/>';
        } else {
            return;
        }
        $this->lscache->esiOn = true;

        $output = $this->setESIBlock($output, $route, $esiBlock, '');
    }

    protected function setESIBlock($output, $route, $esiBlock, $divElement)
    {
        if ($route == 'common/header') {
            $bodyElement = stripos($output, '<body');
            if ($bodyElement === false) {
                return $esiBlock;
            }

            return substr($output, 0, $bodyElement) . $esiBlock;
        }

        //for later usage only, currently no demands
        if (!empty($divElement)) {
            
        }

        return $esiBlock;
    }

    protected function getESIBlock($content, $route, $divElement)
    {
        if ($route == 'common/header') {
            $bodyElement = stripos($content, '<body');
            if ($bodyElement === false) {
                return $content;
            }
            return substr($content, $bodyElement);
        }

        //for later usage only, currently no demands
        if (!empty($divElement)) {
            
        }

        return $content;
    }

    public function onAfterRender($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }
        
        $httpcode=200;
        if (function_exists('http_response_code')) {
            $httpcode = http_response_code();
        }
        
        if ($httpcode > 201) {
            $this->log("Http Response Code Not Cachable:" . $httpcode);
            return;
        }

        $this->checkVary();

        if (!isset($this->lscache->setting['module_lscache_public_ttl'])) {
            $cacheTimeout = 1200000;
        } else {
            $cacheTimeout = $this->lscache->setting['module_lscache_public_ttl'];
            $cacheTimeout = empty($cacheTimeout) ? 1200000 : $cacheTimeout;
        }
        $this->lscache->lscInstance->setPublicTTL($cacheTimeout);
        $this->lscache->lscInstance->cachePublic($this->lscache->cacheTags, $this->lscache->esiOn);
        $this->log();
    }

    public function checkError($route, &$data, &$code){
        if ($this->lscache == null) {
            http_response_code(403);
            return;
        }

        if (($route == 'error/not_found') && isset($this->lscache->setting['module_lscache_cache404']) && ($this->lscache->setting['module_lscache_cache404']=='1') ) {
            $url_data = $this->request->get;
            $route = trim($url_data['route']);
            if($route == 'checkout/cart'){
                return;
            }

            $cacheTimeout = isset($this->lscache->setting['module_lscache_public_ttl']) ? $this->lscache->setting['module_lscache_public_ttl'] : 1200000;
            $this->lscache->lscInstance->setPublicTTL($cacheTimeout);
            $this->lscache->lscInstance->cachePublic( 'p_httpcode_404' );
            return;
        }
    }
    
    public function renderESI()
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            http_response_code(403);
            return;
        }

        if (isset($this->request->get['action'])) {
            if (($this->lscache->esiEnabled) && (substr($this->request->get['action'], 0, 4) == 'esi_')) {
                $purgeTag = $this->request->get['action'];
                $this->lscache->lscInstance->purgePrivate($purgeTag);
                $this->log();
            }

            $this->checkVary();

            $this->response->setOutput($content);
            return;
        }

        if (!isset($this->request->get['esiRoute'])) {
            http_response_code(403);
            return;
        }

        $esiRoute = $this->request->get['esiRoute'];
        $esiKey = 'esi_' . str_replace('/', '_', $esiRoute);
        $module_id = "";
        if (isset($this->request->get['module_id'])) {
            $module_id = $this->request->get['module_id'];
            $esiKey .= '_' . $module_id;
        }
        $this->lscache->cacheTags[] = $esiKey;

        $this->load->model('extension/module/lscache');
        $esiModules = $this->model_extension_module_lscache->getESIModules();
        if (!isset($esiModules[$esiKey])) {
            http_response_code(403);
            return;
        }

        $content = "";
        unset($this->request->get['route']);
        if (empty($module_id)) {
            $content = $this->load->controller($esiRoute);
        } else {
            $setting_info = $this->model_setting_module->getModule($module_id);

            if ($setting_info && $setting_info['status']) {
                $content = $this->load->controller($esiRoute, $setting_info);
            } else {
                http_response_code(403);
                return;
            }
        }

        $content = $this->getESIBlock($content, $esiRoute, '');

        $this->response->setOutput($content);

        $module = $esiModules[$esiKey];
        if ($module['esi_type'] > '1') {
            $cacheTimeout = $module['esi_ttl'];
            $this->lscache->cacheTags[] = $module['esi_tag'];
            $this->lscache->lscInstance->setPublicTTL($cacheTimeout);
            if ($module['esi_type'] == '2') {
                $this->lscache->lscInstance->checkPrivateCookie();
                $this->lscache->lscInstance->setPrivateTTL($cacheTimeout);
                $this->lscache->lscInstance->cachePrivate($this->lscache->cacheTags, $this->lscache->cacheTags);
            } else {
                $this->lscache->lscInstance->cachePublic($this->lscache->cacheTags);
            }
            $this->log();
        }

        $this->event->unregister('controller/*/before', 'extension/module/lscache/onAfterInitialize');
    }

    public function onAfterGetModule($route, &$args, &$output)
    {
        $output['module_id'] = $args[0];
    }

    public function onUserAfterLogin($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }
        $this->lscache->lscInstance->checkPrivateCookie();
        if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true); }
        $this->checkVary();
        if ($this->lscache->esiEnabled) {
            $this->lscache->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }

    public function onUserAfterLogout($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->checkVary();
        if ($this->lscache->esiEnabled) {
            $this->lscache->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }

    protected function checkVary()
    {
        $vary = array();

        if ($this->session->data['currency'] != $this->config->get('config_currency')) {
            $vary['currency'] = $this->session->data['currency'];
        }

        if ((isset($this->session->data['language'])) && ($this->session->data['language'] != $this->config->get('config_language'))) {
            $vary['language'] = $this->session->data['language'];
        }

        
        //cookie not enabled
        if ( (count($vary) == 0) && (($this->checkCookiesEnabled() == FALSE) || ($this->checkisBot() == TRUE)) )  {
            return;
        }


        if ($this->customer->isLogged() && isset($this->lscache->setting['module_lscache_vary_login']) && ($this->lscache->setting['module_lscache_vary_login'] == '1')) {
            $vary['session'] = 'loggedIn';
        }

        //if ($this->checkSafari() && isset($this->lscache->setting['module_lscache_vary_safari']) && ($this->lscache->setting['module_lscache_vary_safari']=='1'))  {
        //    $vary['browser'] = 'safari';
        //}

        if (($browsercheck=$this->checkBrowser()) && isset($this->lscache->setting['module_lscache_vary_safari']) && ($this->lscache->setting['module_lscache_vary_safari']=='1'))  {
            $vary['browser'] = $browsercheck;
        }

	if (($OScheck=$this->checkOS()) && isset($this->lscache->setting['module_lscache_vary_safari']) && ($this->lscache->setting['module_lscache_vary_safari']=='1'))  {
            $vary['os'] = $OScheck;
        }

        if (($apple=$this->checkApple()) && isset($this->lscache->setting['module_lscache_vary_safari']) && ($this->lscache->setting['module_lscache_vary_safari']=='1'))  {
            $vary['apple'] = $apple;
        }
	    
	    
        if (isset($this->lscache->setting['module_lscache_vary_mobile']) && ($this->lscache->setting['module_lscache_vary_mobile'] == '1') && ($device = $this->checkMobile())) {
            $vary['device'] = $device;
        }

        if ((count($vary) == 0) && (isset($_COOKIE['lsc_private']) || defined('LSC_PRIVATE'))) {
            $vary['session'] = 'loggedOut';
        }

        ksort($vary);

        $varyKey = $this->implode2($vary, ',', ':');

        //$this->log('vary:' . $varyKey, 0);
        $this->lscache->lscInstance->checkVary($varyKey);
    }

    public function getProducts($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        //$this->lscache->cacheTags[] = 'Product';
    }

    public function getCategories($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        //$this->lscache->cacheTags[] = 'Category';
    }

    public function getInformations($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        //$this->lscache->cacheTags[] = 'Information';
    }

    public function getManufacturers($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        //$this->lscache->cacheTags[] = 'Manufacturer';
    }

    public function getProduct($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->lscache->cacheTags[] = 'P_' . $args[0];
    }

    public function getCategory($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if (isset($this->lscache->setting['module_lscache_purge_category']) && ($this->lscache->setting['module_lscache_purge_category']=='0') && (strpos($this->lscache->route,'category')==false)) {
            return;
        }
        
        $this->lscache->cacheTags[] = 'C_' . $args[0];
    }

    public function getInformation($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->lscache->cacheTags[] = 'I_' . $args[0];
    }

    public function getManufacturer($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->lscache->cacheTags[] = 'M_' . $args[0];
    }

    public function editCart($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if ($this->lscache->esiEnabled) {
            $this->lscache->lscInstance->checkPrivateCookie();
            if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true); }
            $this->checkVary();
            $purgeTag = 'esi_common_header,esi_cart';
            $this->lscache->lscInstance->purgePrivate($purgeTag);
            $this->log();
        } else {
            $this->checkVary();
        }
    }

    public function confirmOrder($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $purgeTag = 'Product,Category';
        foreach ($this->cart->getProducts() as $product) {
            $purgeTag .= ',P_' . $product['product_id'];
        }

        if ($this->lscache->esiEnabled) {
            $purgeTag .= ',esi_cart';
            $this->lscache->lscInstance->purgePrivate($purgeTag);
            $this->log();
        } else {
            $this->lscache->lscInstance->purgePrivate($purgeTag);
            $this->log();
            $this->checkVary();
        }
    }

    public function addAjax($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->pageCachable)) {
            return;
        }

        $ajax = 'wishlist.add("-1");';
        if ($this->lscache->esiEnabled && isset($this->lscache->setting['module_lscache_ajax_wishlist']) && ($this->lscache->setting['module_lscache_ajax_wishlist'] == '0')) {
            $ajax = '';
        }

        if (isset($this->lscache->setting['module_lscache_ajax_compare']) && ($this->lscache->setting['module_lscache_ajax_compare'] == '1')) {
            $ajax .= 'compare.add("-1");';
        }

        if (!$this->lscache->esiEnabled || (isset($this->lscache->setting['module_lscache_ajax_shopcart']) && ($this->lscache->setting['module_lscache_ajax_shopcart'] == '1'))) {
            $output .= '<script>$(document).ready(function() {try{ ' . $ajax . ' cart.remove("-1");} catch(err){console.log(err.message);}});</script>';
        } else if (!empty($ajax)) {
            $output .= '<script>$(document).ready(function() { try {  ' . $ajax . ' } catch(err){console.log(err.message);}});</script>';
        }

        if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
            $comment = '<!-- LiteSpeed Cache created with user_agent: ' . $_SERVER['HTTP_USER_AGENT'] . ' -->' . PHP_EOL;
            $output = $comment . $output;
        }
    }

    public function checkWishlist($route, &$args)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->addHeader("Access-Control-Allow-Credentials: true");
        $this->response->addHeader("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
        $this->response->addHeader("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

        if (isset($this->request->post['product_id']) && ($this->request->post['product_id'] == "-1")) {
            if ($this->customer->isLogged()) {
                $this->load->model('account/wishlist');
                $total = $this->model_account_wishlist->getTotalWishlist();
            } else {
                $total = isset($this->session->data['wishlist']) ? count($this->session->data['wishlist']) : 0;
            }
            $this->load->language('account/wishlist');
            $text_wishlist = $this->language->get('text_wishlist');
            if (!empty($text_wishlist)) {
                $text_wishlist = 'Wish List (%s)';
            }
            $json = array();
            $json['count'] = $total;
            $json['total'] = sprintf($text_wishlist, $total);

            $this->response->setOutput(json_encode($json));
            return json_encode($json);
        }
    }

    public function checkCompare($route, &$args)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        $this->response->addHeader('Access-Control-Allow-Origin: *');
        $this->response->addHeader("Access-Control-Allow-Credentials: true");
        $this->response->addHeader("Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT");
        $this->response->addHeader("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");

        if (isset($this->request->post['product_id']) && ($this->request->post['product_id'] == "-1")) {
            $total = isset($this->session->data['compare']) ? count($this->session->data['compare']) : 0;
            $this->load->language('product/compare');
            $text_compare = $this->language->get('text_compare');
            $json = array();
            if (!empty($text_compare)) {
                $json['total'] = sprintf($text_compare, $total);
            }
            $json['count'] = $total;
            $this->response->setOutput(json_encode($json));
            return json_encode($json);
        }
    }

    public function editWishlist($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if (($this->lscache->esiEnabled) && isset($this->lscache->setting['module_lscache_ajax_wishlist']) && ($this->lscache->setting['module_lscache_ajax_wishlist'] == '1')) {
            $this->lscache->lscInstance->checkPrivateCookie();
            if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true);}
            $this->checkVary();
            $purgeTag = 'esi_common_header,esi_wishlist';
            $this->lscache->lscInstance->purgePrivate($purgeTag);
            $this->log();
        } else {
            $this->checkVary();
        }
    }

    public function editCompare($route, &$args, &$output)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if (($this->lscache->esiEnabled) && isset($this->lscache->setting['module_lscache_ajax_compare']) && ($this->lscache->setting['module_lscache_ajax_compare'] == '1')) {
            $this->lscache->lscInstance->checkPrivateCookie();
            if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true); }
            $this->checkVary();
            $purgeTag = 'esi_common_header,esi_compare';
            $this->lscache->lscInstance->purgePrivate($purgeTag);
            $this->log();
        } else {
            $this->checkVary();
        }
    }

    public function editCurrency($route, &$args)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if ($this->lscache->esiEnabled) {
            $this->lscache->lscInstance->checkPrivateCookie();
            if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true); }
        }
        $this->session->data['currency'] = $this->request->post['code'];
        $this->checkVary();
    }

    public function editLanguage($route, &$args)
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            return;
        }

        if ($this->lscache->esiEnabled) {
            $this->lscache->lscInstance->checkPrivateCookie();
            if (!defined('LSC_PRIVATE')) { define('LSC_PRIVATE', true); }
        }

        $this->session->data['language'] = $this->request->post['code'];
        $this->checkVary();
    }

    public function log($content = null, $logLevel = self::LOG_INFO)
    {
        if ($this->lscache == null) {
            $this->load->model('extension/module/lscache');
            $this->lscache = (object) array('setting' => $this->model_extension_module_lscache->getItems());
        }

        if ($content == null) {
            if (!$this->lscache->lscInstance) {
                return;
            }
            $content = $this->lscache->lscInstance->getLogBuffer();
        }

        if (!isset($this->lscache->setting['module_lscache_log_level'])) {
            return;
        }

        $logLevelSetting = $this->lscache->setting['module_lscache_log_level'];

        if (isset($this->session->data['lscacheOption']) && ($this->session->data['lscacheOption'] == "debug")) {
            $this->log->write($content);
            return;
        } else if ($logLevelSetting == self::LOG_DEBUG) {
            return;
        } else if ($logLevel > $logLevelSetting) {
            return;
        }

        $logInfo = "LiteSpeed Cache Info:\n";
        if ($logLevel == self::LOG_ERROR) {
            $logInfo = "LiteSpeed Cache Error:\n";
        } else if ($logLevel == self::LOG_DEBUG) {
            $logInfo = "LiteSpeed Cache Debug:\n";
        }

        $this->log->write($logInfo . $content);
    }

    public function recache()
    {

        $cli = false;

        if (php_sapi_name() == 'cli') {
            $cli = true;
        }

        if (isset($this->request->get['from']) && ($this->request->get['from'] == 'cli')) {
            $ip = trim($_SERVER['REMOTE_ADDR']);
            $serverIP = trim($_SERVER['SERVER_ADDR']);
            if ((substr($serverIP, 0, 7) == "127.0.0") || (substr($ip, 0, 7) == "127.0.0") || ($ip == $serverIP)) {
                $cli = true;
            }
        }

	    
        // choose what recache
        $catalog = true;
        $manufacturer = true;
        $categories = true;
        $products = true;

            if ( isset($this->request->get['what']) ) {
                $what_recache = $this->request->get['what'];
                switch ( $what_recache ) {
		            case "1111":
		            case "all": $catalog = true;$manufacturer = true;$categories = true;$products = true;break;
            		case "1000":
            		case "catalog": $catalog = true;$manufacturer = false;$categories = false;$products = false;break;
		            case "100":
		            case "manufacturer": $catalog = false;$manufacturer = true;$categories = false;$products = false;break;
		            case "10":
		            case "categories": $catalog = false;$manufacturer = false;$categories = true;$products = false;break;
		            case "1":
		            case "products": $catalog = false;$manufacturer = false;$categories = false;$products = true;break;
		            case "1001": $catalog = true;$manufacturer = false;$categories = false;$products = true;break;
            		case "1011": $catalog = true;$manufacturer = false;$categories = true;$products = true;break;
		            case "1101": $catalog = true;$manufacturer = true;$categories = false;$products = true;break;
		            case "1100": $catalog = true;$manufacturer = true;$categories = false;$products = false;break;
		            case "11": $catalog = false;$manufacturer = false;$categories = true;$products = true;break;
		            case "101": $catalog = false;$manufacturer = true;$categories = false;$products = true;break;
		            case "111": $catalog = false;$manufacturer = true;$categories = true;$products = true;break;
				    default: $catalog = true;$manufacturer = true;$categories = true;$products = true;
                }
            }
	    

        // recache mode
        $mode_recache_status = false;
            if ( isset($this->request->get['mode']) && ( $this->request->get['mode'] === 'restart'  ) ) {
                        $mode_recache_status = true;
            }
	    

        // renew cache date
        $mode_recache_renew = false;
        $GLOBALS['mode_recache_renew'] = false;
            if ( isset($this->request->get['renew']) && ( $this->request->get['renew'] === '1'  ) ) {
                        $mode_recache_renew = true;
                        $GLOBALS['mode_recache_renew'] = $mode_recache_renew;
            }
	    

        // recache mode for Bots
        $bots_recache_mode = false;
            if ( isset($this->request->get['botsua']) && ( $this->request->get['botsua'] === '1' ) ) {
                        $bots_recache_mode = true;
            }
	    

        // start recache of products from number
        $recache_start_number = 0;
        $GLOBALS['recache_start_number'] = 0;
            if ( isset($this->request->get['startnum']) ) {
                        $recache_start_number = $this->request->get['startnum'];
                        $GLOBALS['recache_start_number'] = $recache_start_number;
            }

        // recache of products to number
        $recache_end_number = 0;
        $GLOBALS['recache_end_number'] = 0;
            if ( isset($this->request->get['endnum']) ) {
                        $recache_end_number = $this->request->get['endnum'];
                        $GLOBALS['recache_end_number'] = $recache_end_number;
            }

        // build urls list for easy purge
        $BuildListForPurge = false;
        $GLOBALS['BuildListForPurge'] = false;
            if ( isset($this->request->get['buildlist']) && ( $this->request->get['buildlist'] === '1' ) ) {
                        $BuildListForPurge = true;
                        $GLOBALS['BuildListForPurge'] = $BuildListForPurge;
            }
	    
	    
	    
        if ($cli) {
            
        } else if (!isset($this->session->data['previouseURL'])) {
            http_response_code(403);
            return;
        } else {
            $previouseURL = $this->session->data['previouseURL'];
            unset($this->session->data['previouseURL']);
        }

        echo 'Recache may take several minutes' . ($cli ? '' : '<br>') . PHP_EOL;
        flush();

        echo 'recache site urls...' . ($cli ? '' : '<br>') . PHP_EOL;

        $urls = array();
        $urls[] = $this->url->link('common/home');
        $urls[] = $this->url->link('information/contact');
        $urls[] = $this->url->link('information/sitemap');
        $urls[] = $this->url->link('product/manufacturer');
        $urls[] = HTTP_SERVER;
        //$urls[] = HTTP_SERVER . 'index.php';
        if (!empty($this->lscache->includeUrls[0])) {
            foreach ($this->lscache->includeUrls as $uri) {
                $urls[] = $this->url->link($uri);
            }
        }
        $this->crawlUrls($urls, $cli);
        $urls = array();

        $this->load->model('extension/module/lscache');
        $pages = $this->model_extension_module_lscache->getPages();

        echo 'recache custom pages urls...' . ($cli ? '' : '<br>') . PHP_EOL;
        foreach ($pages as $page) {
            if ($page['cacheLogout']) {
                $urls[] = $this->url->link($page['route'], '');
            }
        }
        $this->crawlUrls($urls, $cli);
        $urls = array();

        // recache catalog (All Products)
	if ( $catalog ) {
        echo 'recache whole product catalog urls...' . ($cli ? '' : '<br>') . PHP_EOL;
        
        // check if Catalog URLs empty or not and rebuild (restart rebuild)
        if ( $mode_recache_status && $this->CheckDBBuildKeys('catalog',$mode_recache_status) ) {
            $BuildCatalogUrlsValue = $this->BuildCatalogUrls($bots_recache_mode);
        } else {
            if ( $this->CheckDBBuildKeys('catalog') ) {
            $BuildCatalogUrlsValue = $this->BuildCatalogUrls($bots_recache_mode);
            }
        }


        if ( $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_catalog_recache_status') == 'manual') {
            $filter_data = array('filter_category_id'  => 0);
            $num_pages = $this->CountNumberOfPages($filter_data);
			          $urls[] = $this->url->link('product/catalog', '');
			                  if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $urls[] = $this->url->link('product/catalog', $uri);
                                    }
                                }
            for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
			          $urls[] = $this->url->link('product/catalog', 'page=' . $num_page);
			                  if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $urls[] = $this->url->link('product/catalog', $uri . '&page=' . $num_page);
                                    }
                                }
            }
        $this->crawlUrls($urls, $cli);
        } else {
		    $this->BuildCrawlListFromDB('catalog',$cli);
        }

        }
        $urls = array();
	    
	    
        if ( $products || $categories ) { // recache products or categories
        
	$this->load->model('catalog/category');
	$this->load->model('catalog/product');
		
	$categories_1 = $this->model_catalog_category->getCategories(0);
        $categoryPath = array();


        if ( $categories ) { //recache categories
        echo 'recache category urls...' . ($cli ? '' : '<br>') . PHP_EOL;

        // check if Category URLs empty or not and rebuild (restart rebuild)
        if ( $mode_recache_status && $this->CheckDBBuildKeys('category',$mode_recache_status) ) {
            $BuildCategoryUrlsValue = $this->BuildCategoryUrls($bots_recache_mode);
        } else {
            if ( $this->CheckDBBuildKeys('category') ) {
            $BuildCategoryUrlsValue = $this->BuildCategoryUrls($bots_recache_mode);
            }
        }
        
        if ( $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_category_recache_status') == 'manual') {
		foreach ($categories_1 as $category_1) {
            $categoryPath[$category_1['category_id']] = $category_1['category_id'];

			$categories_2 = $this->model_catalog_category->getCategories($category_1['category_id']);

			foreach ($categories_2 as $category_2) {
                $categoryPath[$category_2['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'];

				$categories_3 = $this->model_catalog_category->getCategories($category_2['category_id']);

				foreach ($categories_3 as $category_3) {
                    $categoryPath[$category_3['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'];

                        $categories_4 = $this->model_catalog_category->getCategories($category_3['category_id']);

				        foreach ($categories_4 as $category_4) {
                            $categoryPath[$category_4['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'];

                            $categories_5 = $this->model_catalog_category->getCategories($category_4['category_id']);

                            foreach ($categories_5 as $category_5) {
                                $categoryPath[$category_5['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'] . '_' .  $category_5['category_id'];

                                $filter_data = array('filter_category_id'  => $category_5['category_id']);
                                $num_pages = $this->CountNumberOfPages($filter_data);
                                $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id']);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&' . $uri);
                                                }
                                            }
                                    for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                        $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&page=' . $num_page);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&' . $uri . '&page=' . $num_page);
                                                }
                                            }
                                    }
                            }


                        $filter_data = array('filter_category_id'  => $category_4['category_id']);
                        $num_pages = $this->CountNumberOfPages($filter_data);

                        $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id']);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&' . $uri);
                                                }
                                            }
                                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                    $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&page=' . $num_page);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&' . $uri . '&page=' . $num_page);
                                                }
                                            }
                                }
				        }

                        $filter_data = array('filter_category_id'  => $category_3['category_id']);
                        $num_pages = $this->CountNumberOfPages($filter_data);
					$urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id']);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&' . $uri);
                                                }
                                            }
                                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                    $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&page=' . $num_page);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&' . $uri . '&page=' . $num_page);
                                                }
                                            }
                                }
				}

                $filter_data = array('filter_category_id'  => $category_2['category_id']);
                $num_pages = $this->CountNumberOfPages($filter_data);
				$urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id']) ;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&' . $uri);
                                                }
                                            }
                        for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                            $urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&page=' . $num_page);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&' . $uri . '&page=' . $num_page);
                                                }
                                            }
                        }
			}

			$urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id']);
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '&' . $uri );
                                                }
                                            }
		}
        $this->crawlUrls($urls, $cli);
        } else {
		    $this->BuildCrawlListFromDB('category',$cli);
        }
        
        } else { // build category pathes for product recache
            echo 'build category pathes...' . ($cli ? '' : '<br>') . PHP_EOL;
            
            foreach ($categories_1 as $category_1) {
            $categoryPath[$category_1['category_id']] = $category_1['category_id'];

			$categories_2 = $this->model_catalog_category->getCategories($category_1['category_id']);

			foreach ($categories_2 as $category_2) {
                $categoryPath[$category_2['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'];

				$categories_3 = $this->model_catalog_category->getCategories($category_2['category_id']);

				foreach ($categories_3 as $category_3) {
                    $categoryPath[$category_3['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'];

                        $categories_4 = $this->model_catalog_category->getCategories($category_3['category_id']);

				        foreach ($categories_4 as $category_4) {
                            $categoryPath[$category_4['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'];

                            $categories_5 = $this->model_catalog_category->getCategories($category_4['category_id']);

                            foreach ($categories_5 as $category_5) {
                                $categoryPath[$category_5['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'] . '_' .  $category_5['category_id'];
                            }
				        }
				}
			}
		    }
        }
        } // recache products or categories
        $urls = array();


        if ( $manufacturer ) {
        echo 'recache manufacturers urls...' . ($cli ? '' : '<br>') . PHP_EOL;
		$this->load->model('catalog/manufacturer');
		
        // check if Manufacturer URLs empty or not and rebuild (restart rebuild)
        if ( $mode_recache_status && $this->CheckDBBuildKeys('manufacturer',$mode_recache_status) ) {
            $BuildManufacturerUrlsValue = $this->BuildManufacturerUrls($bots_recache_mode);
        } else {
            if ( $this->CheckDBBuildKeys('manufacturer') ) {
            $BuildManufacturerUrlsValue = $this->BuildManufacturerUrls($bots_recache_mode);
            }
        }
        
        
        if ( $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_manufacturer_recache_status') == 'manual') {
		foreach ($this->model_catalog_manufacturer->getManufacturers() as $result) {

                $filter_data = array('filter_manufacturer_id'  => $result['manufacturer_id']);
        $num_pages = $this->CountNumberOfPages($filter_data);
			         $urls[] = $this->url->link('product/manufacturer/info', 'manufacturer_id=' . $result['manufacturer_id']);
			                    if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $urls[] = $this->url->link('product/manufacturer/info', 'manufacturer_id=' . $result['manufacturer_id'] .'&' . $uri);
                                    }
                                }
                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
			                $urls[] = $this->url->link('product/manufacturer/info', 'manufacturer_id=' . $result['manufacturer_id'] . '&page=' . $num_page);
			                    if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $urls[] = $this->url->link('product/manufacturer/info', 'manufacturer_id=' . $result['manufacturer_id'] . '&' . $uri . '&page=' . $num_page);
                                    }
                                }
                }
		}
        $this->crawlUrls($urls, $cli);
		} else {
		    $this->BuildCrawlListFromDB('manufacturer',$cli);
		}

        }
        $urls = array();
        
        echo 'recache information urls...' . ($cli ? '' : '<br>') . PHP_EOL;
        $this->load->model('catalog/information');
        foreach ($this->model_catalog_information->getInformations() as $result) {
            $urls[] = $this->url->link('information/information', 'information_id=' . $result['information_id']);
        }
        $this->crawlUrls($urls, $cli);
        $urls = array();

        if ( $products ) {
        echo 'recache product urls...' . ($cli ? '' : '<br>') . PHP_EOL;
        $UrlsCount = 0;
        $UrlsCountCount = 0;
        
        // check if Product URLs List empty or not and rebuild (restart rebuild)
        if ( $mode_recache_status && $this->CheckDBBuildKeys('product_list',$mode_recache_status) ) {
            $BuildListOfProductUrlsValue = $this->BuildListOfProductUrls($categoryPath,$bots_recache_mode);
        } else {
            if ( $this->CheckDBBuildKeys('product_list') ) {
            $BuildListOfProductUrlsValue = $this->BuildListOfProductUrls($categoryPath,$bots_recache_mode);
            }
        }

        if ( $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_product_list_recache_status') == 'manual') {
		foreach ($this->model_catalog_product->getProducts() as $result) {
            foreach ($this->model_catalog_product->getCategories($result['product_id']) as $category) {
                if(isset( $categoryPath[$category['category_id']] )){
                    $urls[] = $this->url->link('product/product', 'path=' . $categoryPath[$category['category_id']] . '&product_id=' . $result['product_id']);
                    $UrlsCount++;
                }
            }

            $urls[] = $this->url->link('product/product', 'manufacturer_id=' . $result['manufacturer_id'] . '&product_id=' . $result['product_id']);
            $UrlsCount++;

            $urls[] = $this->url->link('product/product', 'product_id=' . $result['product_id']);
            $UrlsCount++;
            if ( $UrlsCount > 2048 ) {
                $UrlsCountCount++;
                echo 'recache '. $UrlsCountCount . ' part of product urls...' . ($cli ? '' : '<br>') . PHP_EOL;
                $this->crawlUrls($urls, $cli);
                $urls = array();
                $UrlsCount = 0;
            }
		}

            if ( $UrlsCountCount > 0 ) {
                echo 'recache '. $UrlsCountCount . ' part of product urls...' . ($cli ? '' : '<br>') . PHP_EOL;
            }
            $this->crawlUrls($urls, $cli);
        } else if ( $GLOBALS['BuildListForPurge'] ) {
            $this->BuildCrawlListFromDB('product_list',$cli);
            } else {
                $this->BuildCrawlListFromDB('product_list',$cli);
            }
        
        }

        $data['success'] = $this->language->get('text_success');

        if (!$cli) {
            echo '<script type="text/javascript">
                       window.location = "' . str_replace('&amp;', '&', $previouseURL) . '"
                  </script>';
        }
    }

    private function crawlUrls($urls, $cli = false)
    {
        set_time_limit(0);

        $count = count($urls);
        if ($count < 1) {
            return "";
        }

        $cached = 0;
        $acceptCode = array(200, 201);
        $begin = microtime();
        $success = 0;
        $current = 1;

        ob_implicit_flush(TRUE);
        if (ob_get_contents()) {
            ob_end_clean();
        }
        $this->log('Start Recache:');

        $recacheOption = isset($this->lscache->setting['module_lscache_recache_option']) ? $this->lscache->setting['module_lscache_recache_option'] : 0;
        $recacheUserAgents = isset($this->lscache->setting['module_lscache_recache_userAgent']) ? preg_split( '/\n|\r\n?/', $this->lscache->setting['module_lscache_recache_userAgent']) : array("lscache_runner");
        if (empty($recacheUserAgents) || empty($recacheUserAgents[0])) {
            $recacheUserAgents = array('lscache_runner');
        }

        if ($this->lscache->esiEnabled) {
            $cookies = array('', '_lscache_vary=session%3AloggedOut;lsc_private=e70f67d087a65a305e80267ba3bfbc97');
        } else {
            $cookies = array('');
        }
        
        $this->load->model('localisation/language');
        $languages = array();
        $results = $this->model_localisation_language->getLanguages();
        foreach ($results as $result) {
            if ($result['status']) {
                $languages[] = array(
                    'code' => $result['code'],
                    'name' => $result['name'],
                );
            }
            if (($recacheOption == '1') && ($result['code'] != $this->config->get('config_language'))) {
                $cookies[] = '_lscache_vary=language%3A' . $result['code'] . ';language=' . $result['code'] . ';lsc_private=e70f67d087a65a305e80267ba3bfbc97';
            }
        }

        $this->load->model('localisation/currency');
        $currencies = array();
        $results = $this->model_localisation_currency->getCurrencies();
        foreach ($results as $result) {
            if ($result['status']) {
                $currencies[] = array(
                    'code' => $result['code'],
                    'title' => $result['title'],
                );
            }

            if (($recacheOption == '2') && ($result['code'] != $this->config->get('config_currency'))) {
                $cookies[] = '_lscache_vary=currency%3A' . $result['code'] . ';currency=' . $result['code'] . ';lsc_private=e70f67d087a65a305e80267ba3bfbc97';
            }
        }

        if ($recacheOption == '3') {
            foreach ($languages as $language) {
                foreach ($currencies as $currency) {
                    if (($language['code'] != $this->config->get('config_language')) && ($currency['code'] != $this->config->get('config_currency'))) {
                        $cookies[] = '_lscache_vary=language%3A' . $language['code'] . ',currency%3A' . $currency['code'] . ';language=' . $language['code'] . ';currency=' . $currency['code'] . ';lsc_private=e70f67d087a65a305e80267ba3bfbc97';
                    }
                }
            }
        }

        foreach ($urls as $url) {

            $url = str_replace('&amp;', '&', $url);
            $url = str_replace('&amp%3B', '&', $url);
            $url = str_replace('?amp%3B', '?', $url);
            $url = str_replace('page%3D', '&page=', $url);

                        $cookies1 = array('');
            if ( $cookies == $cookies1 ) {
                $cookie = '';
                foreach($recacheUserAgents as $userAgent){
                $cookie = $this->CookiesForCrawler($userAgent,'',FALSE);

					//error_log(print_r('cookie for crawler (initial empty) : ' . $cookie,true));

                    $this->log('crawl:'.$url . '    cookie:' . $cookie);
                    $start = microtime();

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/html; charset=utf-8"));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_DEFAULT);
                    curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, true);
                    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
                    curl_setopt($ch, CURLOPT_ENCODING, "");

                    if($cli && ($userAgent=='lscache_runner')){
                        $userAgent = 'lscache_walker';
                    }

                    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

                    if($cookie != ''){
                        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
                    }

                    if ( $GLOBALS['mode_recache_renew'] ) {
                    //curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REFRESH");
                    }

                    $buffer = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if (in_array($httpcode, $acceptCode)) {
                        $success++;
                    } else if($httpcode==428){
                        if(!$cli){
                            echo 'Web Server crawler feature not enabled, please check <a href="https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:enabling_the_crawler" target="_blank">web server settings</a>';
                        } else {
                            echo 'Web Server crawler feature not enabled, please check "https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:enabling_the_crawler"' .  PHP_EOL;
                        }
                        $this->log('httpcode:'.$httpcode);
                        sleep(5);
                        return;
                    } else {
                        $this->log('httpcode:'.$httpcode);
                    }

                    $end = microtime();
                    $diff = $this->microtimeMinus($start, $end);
                    //usleep(round($diff));
                    //echo $diff . ' microseconds for one run' . PHP_EOL;
                }
            }   else {
            foreach($cookies as $cookie){
                foreach($recacheUserAgents as $userAgent){

                    if ( stripos($cookie, '_lscache_vary') !== FALSE ) {
                        $cookie = $this->CookiesForCrawler($userAgent,$cookie,TRUE);
                    }

					//error_log(print_r('cookie for crawler not empty: ' . $cookie,true));

                    $this->log('crawl:'.$url . '    cookie:' . $cookie);
                    $start = microtime();
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/html; charset=utf-8"));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_DEFAULT);
                    curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, true);
                    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
                    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2);
                    curl_setopt($ch, CURLOPT_ENCODING, "");

                    if($cli && ($userAgent=='lscache_runner')){
                        $userAgent = 'lscache_walker';
                    }

                    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

                    if($cookie != ''){
                        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
                    }

                    if ( $GLOBALS['mode_recache_renew'] ) {
                    //curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "REFRESH");
                    }

                    $buffer = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if (in_array($httpcode, $acceptCode)) {
                        $success++;
                    } else if($httpcode==428){
                        if(!$cli){
                            echo 'Web Server crawler feature not enabled, please check <a href="https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:enabling_the_crawler" target="_blank">web server settings</a>';
                        } else {
                            echo 'Web Server crawler feature not enabled, please check "https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:enabling_the_crawler"' .  PHP_EOL;
                        }
                        $this->log('httpcode:'.$httpcode);
                        sleep(5);
                        return;
                    } else {
                        $this->log('httpcode:'.$httpcode);
                    }

                    $end = microtime();
                    $diff = $this->microtimeMinus($start, $end);
                    usleep(round($diff));
                }

            }
            }

                if ( $GLOBALS['BuildListForPurge'] ) {
                    echo $url . ($cli ? '' : '<br>') . PHP_EOL;
                } else if ( $showpathcount ) {
                    echo $current . '/' . $count . '/' . $pathcount . '/' . $totalurls . ' ' . $url . ' : ' . $httpcode . ($cli ? '' : '<br>') . PHP_EOL;
                } else {
                    echo $current . '/' . $count . ' ' . $url . ' : ' . $httpcode . ($cli ? '' : '<br>') . PHP_EOL;
                }

            //if($cli){
            //    echo $current . '/' . $count . ' ' . $url . ' : ' . $httpcode . PHP_EOL;
            //} else {
            //    echo $current . '/' . $count . ' ' . $url . ' : ' . $httpcode . '<br/>'. PHP_EOL;
            //}
		
            flush();

            $current++;
        }

        $totalTime = round($this->microtimeMinus($begin, microtime()) / 1000000);

        return $totalTime;  //script redirect to previous page
    }

    public function purgeAll()
    {
        $cli = false;

        if (php_sapi_name() == 'cli') {
            $cli = true;
        }

        if (isset($this->request->get['from']) && ($this->request->get['from'] == 'cli')) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $serverIP = $_SERVER['SERVER_ADDR'];
            if ((substr($serverIP, 0, 7) == "127.0.0") || (substr($ip, 0, 7) == "127.0.0") || ($ip == $serverIP)) {
                $cli = true;
            }
        }

        if (!$cli) {
            http_response_code(403);
            return;
        }

        $url = $this->url->link('extension/module/lscache/purgeAllAction');
        $content = $this->file_get_contents_curl($url);
        echo $content;
    }

    public function purgeAllAction()
    {
        if (($this->lscache == null) || (!$this->lscache->cacheEnabled)) {
            http_response_code(403);
            return;
        }

        $visitorIP = $_SERVER['REMOTE_ADDR'];
        $serverIP = $_SERVER['SERVER_ADDR'];

        if (($visitorIP == "127.0.0.1") || ($serverIP == "127.0.0.1") || ($visitorIP == $serverIP)) {
            $lscInstance = new LiteSpeedCacheCore();
            $lscInstance->purgeAllPublic();
            echo 'All LiteSpeed Cache has been purged' . PHP_EOL;
            flush();
        } else {
            echo 'Operation not allowed from this device' . PHP_EOL;
            flush();
            http_response_code(403);
        }
    }

    private function microtimeMinus($start, $end)
    {
        list($s_usec, $s_sec) = explode(" ", $start);
        list($e_usec, $e_sec) = explode(" ", $end);
        $diff = ((int) $e_sec - (int) $s_sec) * 1000000 + ((float) $e_usec - (float) $s_usec) * 1000000;
        return $diff;
    }

    protected function emptySession()
    {
        if (isset($_COOKIE['lsc_private'])) {
            return false;
        }

        if ($this->customer->isLogged()) {
            return false;
        }

        if ($this->session->data['currency'] != $this->config->get('config_currency')) {
            return false;
        }

        if ($this->session->data['language'] != $this->config->get('config_language')) {
            return false;
        }

        return true;
    }

    protected function implode2(array $arr, $d1, $d2)
    {
        $arr1 = array();

        foreach ($arr as $key => $val) {
            $arr1[] = urlencode($key) . $d2 . urlencode($val);
        }
        return implode($d1, $arr1);
    }

    protected function file_get_contents_curl($url)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }

    protected function checkMobile($ua='')
    {
        if(empty($ua)){
            $ua = $_SERVER['HTTP_USER_AGENT'];
        }

        if (defined('JOURNAL3_ACTIVE')) {
            //error_log(print_r('Journal3 mobile detection algorithm used',true));
            if (strpos($ua, 'iPhone') !== FALSE) {
                return 'mobile';
            } elseif (strpos($ua, 'iPad') !== FALSE) {
                return 'tablet';
            } elseif ((strpos($ua, 'Android') !== FALSE) && (strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome') !== FALSE) && (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== FALSE)) {
                return 'mobile';
            } elseif ((strpos($ua, 'Android') !== FALSE) && (strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome') !== FALSE) && (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') == FALSE)) {
                return 'tablet';
            } else {
                return false;
            }
        } else {
            //only use .htaccess rule to mark separate cache copy for mobile view
            return false;
//            include_once(DIR_SYSTEM . 'library/Mobile_Detect/Mobile_Detect.php');
//            $detect = new Mobile_Detect();
//            if ($detect->isTablet()) {
//                return 'tablet';
//            } else if ($detect->isMobile()) {
//                return 'mobile';
//            } else {
//                return false;
//            }
        }
    }

    protected function checkSafari($ua='')
    {
        if(empty($ua)){
            $ua = $_SERVER['HTTP_USER_AGENT'];
        }
        
        if (strpos($ua, 'CriOS') !== FALSE) {
            return FALSE;
        }

        if (strpos($ua, 'Chrome') !== FALSE) {
            return FALSE;
        }
        if (strpos($ua, 'Safari') !== FALSE) {
            return TRUE;
        }
        return FALSE;
    }

    protected function checkCookiesEnabled()
    {
        if (isset($_SERVER['HTTP_COOKIE'])) {
            return TRUE;
        }
        return FALSE;
    }
    

    protected function CountNumberOfPages($filter_data) {

        if (isset($this->request->get['limit'])) {
            $limit = (int) $this->request->get['limit'];
        } else if (defined('JOURNAL3_ACTIVE')) {
            $limit = $this->journal3->themeConfig('product_limit');
        } else {
            return 1;
        }

        if (defined('JOURNAL3_ACTIVE')) {
            $this->load->model('journal3/filter');

            $filter_data = array_merge($this->model_journal3_filter->parseFilterData(), $filter_data);

            $this->model_journal3_filter->setFilterData($filter_data);

            \Journal3\Utils\Profiler::start('journal3/filter/total_products');

            $product_total = $this->model_journal3_filter->getTotalProducts();

            \Journal3\Utils\Profiler::end('journal3/filter/total_products');
        } else {
            $product_total = $this->model_catalog_product->getTotalProducts($filter_data);
        }

        $num_pages = ceil($product_total / $limit);

        return $num_pages;
    }


	
    protected function checkBrowser() {

        if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
        if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'OPR') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'OPT') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Opera') !== FALSE) ) {
            //return 'opera';
            return 'chrome';
        }

		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'FxiOS') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Firefox') !== FALSE) ) {
            //return 'firefox';
            return 'chrome';
        }

		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Edg') !== FALSE) ) {
            //return 'edge';
            return 'chrome';
        }

		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'YaBrowser') !== FALSE) ) {
            //return 'yandex';
            return 'chrome';
        }

		//if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Lighthouse') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Headless') !== FALSE) ) {
		//if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Lighthouse') !== FALSE) ) {
        //    return 'lighthouse';
        //}

		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'CriOS') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Chrome') !== FALSE) ) {
            return 'chrome';
        }

		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Safari') !== FALSE) ) {
		            if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Version/14') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Version/15') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Version/16') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Version/17') !== FALSE) ) {
                    return 'chrome';
		            }
            return 'safari';
        }

        if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'Instagram') !== FALSE) ) {
            //return 'instagram';
            //return 'safari';
            return 'chrome';
        }
        return 'unknown';
        }
        return FALSE;
     }
	
	
	protected function checkApple() {

        if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
        if (stripos($_SERVER['HTTP_USER_AGENT'], 'Macintosh') !== FALSE) {
            return 'macintosh';
        }
        if (stripos($_SERVER['HTTP_USER_AGENT'], 'iPhone') !== FALSE) {
            return 'iphone';
        }
        if (stripos($_SERVER['HTTP_USER_AGENT'], 'iPad') !== FALSE) {
            return 'ipad';
        }
        }
        return FALSE;
    }
	
	
	protected function checkOS() {

        if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
        if (stripos($_SERVER['HTTP_USER_AGENT'], 'Windows') !== FALSE) {
            return 'windows';
        }
        if (stripos($_SERVER['HTTP_USER_AGENT'], 'Linux') !== FALSE) {
            return 'linux';
        }
        }
        return FALSE;
    }
	
	
	protected function checkisBot() {

		if ( isset($_SERVER['HTTP_USER_AGENT']) ) {
		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'bot') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'compatible') !== FALSE) ) {
		return TRUE;
		}
		if ( (stripos($_SERVER['HTTP_USER_AGENT'], 'image') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'cfnetwork') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'favicon') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'facebook') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'crawler') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'spider') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'Headless') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'runner') !== FALSE) || (stripos($_SERVER['HTTP_USER_AGENT'], 'walker') !== FALSE) ) {
		return TRUE;
		}
		return FALSE;
	    }
	    return TRUE;

    }
	
	
	protected function BuildListOfProductUrls($categoryPath1,$bots_recache_mode=false) {
	    
        $UrlsCount1 = 0;
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "lscache_product_list_urls_list` ( `url_list_id` int(11) NOT NULL AUTO_INCREMENT, `lscache_product_url` varchar(255) NOT NULL, `recache_status` tinyint(1) NOT NULL, PRIMARY KEY (`url_list_id`)) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
		foreach ($this->model_catalog_product->getProducts() as $result) {
            foreach ($this->model_catalog_product->getCategories($result['product_id']) as $category) {
                if(isset( $categoryPath1[$category['category_id']] )){
                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_product_list_urls_list SET lscache_product_url = '" . 'path=' . $categoryPath1[$category['category_id']] . '&product_id=' . $result['product_id'] . "' ");
                    $UrlsCount1++;
                }
            }

            $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_product_list_urls_list SET lscache_product_url = '" . 'manufacturer_id=' . $result['manufacturer_id'] . '&product_id=' . $result['product_id'] . "' ");
			$UrlsCount1++;

            $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_product_list_urls_list SET lscache_product_url = '" . 'product_id=' . $result['product_id'] . "' ");
            $UrlsCount1++;
            
            //Journal3 QuickView url
            if ( defined('JOURNAL3_ACTIVE') && !$bots_recache_mode ) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_product_list_urls_list SET lscache_product_url = '" . 'product_id=' . $result['product_id'] . '&popup=quickview' . "' ");
                $UrlsCount1++;
            //Journal3 Specific product popup    
                 if( !empty($this->lscache->includeFilters[0]) ) {
                                    foreach($this->lscache->includeFilters as $uri) {
                                        $uri = str_replace('&amp;', '&', $uri);
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_product_list_urls_list SET lscache_product_url = '" . 'product_id=' . $result['product_id'] . '&' . $uri . "' ");
                                        $UrlsCount1++;
                                    }
                                }
            }
            
		}
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_product_list_recache_status' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_product_list_recache_status', `value` = 'full'");
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_product_list_recache_total' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_product_list_recache_total', `value` = '" . $UrlsCount1 . "' ");

		return $UrlsCount1;

    }
	
	
	protected function BuildUrlsListForRecache($FirstItem,$LastItem) {
	    
	    $UrlsList = array();
	    $PreviousURL = '';
	    for ($num_item = $FirstItem ; $num_item <= $LastItem ;  $num_item++ ) {
	        $PathToRecache = (array)$this->db->query("SELECT `lscache_product_url` FROM `" . DB_PREFIX . "lscache_product_list_urls_list` WHERE url_list_id = '" . $num_item ."' " );
	        if (stripos($PathToRecache['row']['lscache_product_url'], 'popup') !== FALSE) {
	            $CurrentURL = $this->url->link('journal3/product', $PathToRecache['row']['lscache_product_url'] );
	            $UrlsList[] = $CurrentURL;
	            // $UrlsList[] = $this->url->link('journal3/product', $PathToRecache['row']['lscache_product_url'] );
	        } else {
	            // reduce urls numbers when SEO enabled
	            $CurrentURL = $this->url->link('product/product', $PathToRecache['row']['lscache_product_url'] );
	            if ( $CurrentURL !== $PreviousURL ) {
	                $UrlsList[] = $CurrentURL;
	            }
	        // $UrlsList[] = $this->url->link('product/product', $PathToRecache['row']['lscache_product_url'] );
	        }
	        $PreviousURL = $CurrentURL;
	    }

	    return $UrlsList;
	}
	
	
	protected function BuildCatalogUrls($bots_recache_mode=false) {
	    
        $UrlsCount1 = 0;
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "lscache_catalog_urls_list` ( `url_list_id` int(11) NOT NULL AUTO_INCREMENT, `lscache_catalog_url` varchar(255) NOT NULL, `recache_status` tinyint(1) NOT NULL, PRIMARY KEY (`url_list_id`)) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
        
            $filter_data = array('filter_category_id'  => 0);
            $num_pages = $this->CountNumberOfPages($filter_data);
                  $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_catalog_urls_list SET lscache_catalog_url = '' ");
                  $UrlsCount1++;
			                  if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $uri = str_replace('&amp;', '&', $uri);
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_catalog_urls_list SET lscache_catalog_url = '" . $uri . "' ");
                                        $UrlsCount1++;
                                    }
                                }
            if ( !$bots_recache_mode ) {
            for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
			          $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_catalog_urls_list SET lscache_catalog_url = '" . 'page=' . $num_page . "' ");
			          $UrlsCount1++;
			                  if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $uri = str_replace('&amp;', '&', $uri);
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_catalog_urls_list SET lscache_catalog_url = '" . $uri . '&page=' . $num_page . "' ");
                                        $UrlsCount1++;
                                    }
                                }
            }
            }
        
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_catalog_recache_status' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_catalog_recache_status', `value` = 'full'");
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_catalog_recache_total' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_catalog_recache_total', `value` = '" . $UrlsCount1 . "' ");

		return $UrlsCount1;

    }
	
	
	protected function BuildCatalogUrlsListForRecache($FirstItem,$LastItem) {
	    
	    $UrlsList = array();
	    for ($num_item = $FirstItem ; $num_item <= $LastItem ;  $num_item++ ) {
	        $PathToRecache = (array)$this->db->query("SELECT `lscache_catalog_url` FROM `" . DB_PREFIX . "lscache_catalog_urls_list` WHERE url_list_id = '" . $num_item ."' " );
	        $UrlsList[] = $this->url->link('product/catalog', $PathToRecache['row']['lscache_catalog_url'] );
	    }
	    return $UrlsList;
	}
	
	
	protected function BuildManufacturerUrls($bots_recache_mode=false) {
	    
        $UrlsCount1 = 0;
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "lscache_manufacturer_urls_list` ( `url_list_id` int(11) NOT NULL AUTO_INCREMENT, `lscache_manufacturer_url` varchar(255) NOT NULL, `recache_status` tinyint(1) NOT NULL, PRIMARY KEY (`url_list_id`)) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
        
        
		foreach ($this->model_catalog_manufacturer->getManufacturers() as $result) {

                $filter_data = array('filter_manufacturer_id'  => $result['manufacturer_id']);
                     $num_pages = $this->CountNumberOfPages($filter_data);

			         $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_manufacturer_urls_list SET lscache_manufacturer_url = '" . 'manufacturer_id=' . $result['manufacturer_id'] . "' ");
			         $UrlsCount1++;
			                    if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $uri = str_replace('&amp;', '&', $uri);
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_manufacturer_urls_list SET lscache_manufacturer_url = '" . 'manufacturer_id=' . $result['manufacturer_id'] . '&' . $uri . "' ");
                                        $UrlsCount1++;
                                    }
                                }
                if ( !$bots_recache_mode ) {
                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
			                $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_manufacturer_urls_list SET lscache_manufacturer_url = '" . 'manufacturer_id=' . $result['manufacturer_id'] . '&page=' . $num_page . "' ");
			                $UrlsCount1++;
			                    if( !empty($this->lscache->includeSorts[0]) ) {
                                    foreach($this->lscache->includeSorts as $uri) {
                                        $uri = str_replace('&amp;', '&', $uri);
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_manufacturer_urls_list SET lscache_manufacturer_url = '" . 'manufacturer_id=' . $result['manufacturer_id'] . '&' . $uri . '&page=' . $num_page . "' ");
                                        $UrlsCount1++;
                                    }
                                }
                }
                }
		}
        
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_manufacturer_recache_status' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_manufacturer_recache_status', `value` = 'full'");
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_manufacturer_recache_total' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_manufacturer_recache_total', `value` = '" . $UrlsCount1 . "' ");

		return $UrlsCount1;

    }
	
	
	protected function BuildManufacturerUrlsListForRecache($FirstItem,$LastItem) {
	    
	    $UrlsList = array();
	    for ($num_item = $FirstItem ; $num_item <= $LastItem ;  $num_item++ ) {
	        $PathToRecache = (array)$this->db->query("SELECT `lscache_manufacturer_url` FROM `" . DB_PREFIX . "lscache_manufacturer_urls_list` WHERE url_list_id = '" . $num_item ."' " );
	        $UrlsList[] = $this->url->link('product/manufacturer/info', $PathToRecache['row']['lscache_manufacturer_url'] );
	    }
	    return $UrlsList;
	}
	
	
	protected function BuildCategoryUrls($bots_recache_mode=false) {
	    
        $UrlsCount1 = 0;
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "lscache_category_urls_list` ( `url_list_id` int(11) NOT NULL AUTO_INCREMENT, `lscache_category_url` varchar(255) NOT NULL, `recache_status` tinyint(1) NOT NULL, PRIMARY KEY (`url_list_id`)) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
        
        $categories_1 = $this->model_catalog_category->getCategories(0);
        
		foreach ($categories_1 as $category_1) {
            $categoryPath[$category_1['category_id']] = $category_1['category_id'];

			$categories_2 = $this->model_catalog_category->getCategories($category_1['category_id']);

			foreach ($categories_2 as $category_2) {
                $categoryPath[$category_2['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'];

				$categories_3 = $this->model_catalog_category->getCategories($category_2['category_id']);

				foreach ($categories_3 as $category_3) {
                    $categoryPath[$category_3['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'];

                        $categories_4 = $this->model_catalog_category->getCategories($category_3['category_id']);

				        foreach ($categories_4 as $category_4) {
                            $categoryPath[$category_4['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'];

                            $categories_5 = $this->model_catalog_category->getCategories($category_4['category_id']);

                            foreach ($categories_5 as $category_5) {
                                $categoryPath[$category_5['category_id']] = $category_1['category_id'] . '_' . $category_2['category_id'] . '_' .  $category_3['category_id'] . '_' .  $category_4['category_id'] . '_' .  $category_5['category_id'];

                                $filter_data = array('filter_category_id'  => $category_5['category_id']);
                                $num_pages = $this->CountNumberOfPages($filter_data);
                                $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . "' ");
                                $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&' . $uri . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                    if ( !$bots_recache_mode ) {
                                    for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&page=' . $num_page . "' ");
                                        $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '_' . $category_5['category_id'] . '&' . $uri . '&page=' . $num_page . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                    }
                                    }
                            }

                        $filter_data = array('filter_category_id'  => $category_4['category_id']);
                        $num_pages = $this->CountNumberOfPages($filter_data);

                        $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . "' ");
                        $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&' . $uri . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                if ( !$bots_recache_mode ) {
                                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&page=' . $num_page . "' ");
                                    $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '_' . $category_4['category_id'] . '&' . $uri . '&page=' . $num_page . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                }
                                }
				        }

                        $filter_data = array('filter_category_id'  => $category_3['category_id']);
                        $num_pages = $this->CountNumberOfPages($filter_data);
    					$this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . "' ");
    					$UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&' . $uri . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                if ( !$bots_recache_mode ) {
                                for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&page=' . $num_page . "' ");
                                    $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '_' . $category_3['category_id'] . '&' . $uri . '&page=' . $num_page . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                                }
                                }
				}

                $filter_data = array('filter_category_id'  => $category_2['category_id']);
                $num_pages = $this->CountNumberOfPages($filter_data);
				$this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . "' ");
				$UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&' . $uri . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                        if ( !$bots_recache_mode ) {
                        for ($num_page = 2 ; $num_page <= $num_pages ;  $num_page++ ) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&page=' . $num_page . "' ");
                            $UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '_' . $category_2['category_id'] . '&' . $uri . '&page=' . $num_page . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
                        }
                        }
			}

			$urls[] =  $this->url->link('product/category', 'path=' . $category_1['category_id']);
			$this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . "' ");
			$UrlsCount1++;
			                                if( !empty($this->lscache->includeSorts[0]) ) {
                                                foreach($this->lscache->includeSorts as $uri) {
                                                    $urls[] = $this->url->link('product/category', 'path=' . $category_1['category_id'] . '&' . $uri );
                                                    $uri = str_replace('&amp;', '&', $uri);
                                                    $this->db->query("INSERT INTO " . DB_PREFIX . "lscache_category_urls_list SET lscache_category_url = '" . 'path=' . $category_1['category_id'] . '&' . $uri . "' ");
                                                    $UrlsCount1++;
                                                }
                                            }
		}
        

		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_category_recache_status' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_category_recache_status', `value` = 'full'");
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = 'module_lscache_category_recache_total' ");
		$this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = 'module_lscache_category_recache_total', `value` = '" . $UrlsCount1 . "' ");

		return $UrlsCount1;

    }
	
	
	protected function BuildCategoryUrlsListForRecache($FirstItem,$LastItem) {
	    
	    $UrlsList = array();
	    for ($num_item = $FirstItem ; $num_item <= $LastItem ;  $num_item++ ) {
	        $PathToRecache = (array)$this->db->query("SELECT `lscache_category_url` FROM `" . DB_PREFIX . "lscache_category_urls_list` WHERE url_list_id = '" . $num_item ."' " );
	        $UrlsList[] = $this->url->link('product/category', $PathToRecache['row']['lscache_category_url'] );
	    }
	    return $UrlsList;
	}
	
	
	protected function CheckDBBuildKeys($buildpath,$keysexist=false) {

        if ( !$this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_'. $buildpath . '_recache_status') ) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_recache_status' . "', `value` = 'empty' ");
            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_recache_total' . "', `value` = '0' ");
            $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_last_recached' . "', `value` = '0' ");
        }

        if ( ($this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_'. $buildpath . '_recache_status') == 'empty') || $keysexist ) {
            $this->db->query("DROP TABLE IF EXISTS " . DB_PREFIX . "lscache_" . $buildpath . "_urls_list ");
            $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = '" . 'module_lscache_' . $buildpath . '_recache_total' . "' ");
		    $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_recache_total' . "', `value` = '0' ");
            $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = '" . 'module_lscache_' . $buildpath . '_last_recached' . "' ");
		    $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_last_recached' . "', `value` = '0' ");
		    return true;
        } else {
            return false;
        }
	}
	

	protected function BuildCrawlListFromDB($buildpath,$cli) {
	    
	     if ( $GLOBALS['recache_start_number'] == 0 ) {
	     $LastRecachedItem = $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_' . $buildpath . '_last_recached');
	    } else {
	        $LastRecachedItem = $GLOBALS['recache_start_number'];
	    }
        $LastRecachedItemStep = 0;
        $TotalUrlsInList = $this->model_extension_module_lscache->getSettingValue('module_lscache','module_lscache_' . $buildpath . '_recache_total');
        // crawl Urls from built Product List
        for ($i = $LastRecachedItem + 1; $i <= $TotalUrlsInList; $i = $i + 1019 + $LastRecachedItemStep ) {
            $LastRecacheItemNew = $i+1019;
            if ( $GLOBALS['recache_end_number'] == 0 ) {
                if ( $LastRecacheItemNew > $TotalUrlsInList ) $LastRecacheItemNew = $TotalUrlsInList;
            } else {
                if ( $LastRecacheItemNew > $GLOBALS['recache_end_number'] ) $LastRecacheItemNew = $GLOBALS['recache_end_number'];
                if ( $LastRecacheItemNew > $TotalUrlsInList ) $LastRecacheItemNew = $TotalUrlsInList;
                if ( $i >= $GLOBALS['recache_end_number'] ) break;
            }
            $UrlsCountCount = intval($i/1020)+1;
            echo 'recaching '. $UrlsCountCount . ' part of ' . $buildpath . ' urls list...' . ($cli ? '' : '<br>') . PHP_EOL;
            //$UrlsTest = $this->BuildUrlsListForRecache($i,$LastRecacheItemNew);
                switch ( $buildpath ) {
		            case "product_list": $UrlsTest = $this->BuildUrlsListForRecache($i,$LastRecacheItemNew);break;
		            case "category":     $UrlsTest = $this->BuildCategoryUrlsListForRecache($i,$LastRecacheItemNew);break;
            		case "manufacturer": $UrlsTest = $this->BuildManufacturerUrlsListForRecache($i,$LastRecacheItemNew);break;
            		case "catalog":      $UrlsTest = $this->BuildCatalogUrlsListForRecache($i,$LastRecacheItemNew);break;
                }
            //if ( $BuildListForPurge ) {
            $this->crawlUrls($UrlsTest, $cli, true , $UrlsCountCount, $TotalUrlsInList);
            //} else {
            //    $this->crawlUrls($UrlsTest, $cli, true , $UrlsCountCount, $TotalUrlsInList, false);
            //}
            $LastRecachedItemStep = 1;
            $UrlsTest = array();
            if ( $GLOBALS['recache_start_number'] == 0 ) {
            $this->db->query("DELETE FROM " . DB_PREFIX . "setting WHERE store_id = '0' AND `code` = 'module_lscache' AND `key` = '" . 'module_lscache_' . $buildpath . '_last_recached' . "' ");
		    $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '0', `code` = 'module_lscache', `key` = '" . 'module_lscache_' . $buildpath . '_last_recached' . "', `value` = '" . $LastRecacheItemNew . "' ");
            }
		    echo 'recached ' . $buildpath . ' urls list from ' . $i . ' to ' . $LastRecacheItemNew . ' completed' . ($cli ? '' : '<br>') . PHP_EOL;
        }

	}
	
    
	protected function CookiesForCrawler($userAgent,$cookie,$is_lscache) {

     if ( $is_lscache == TRUE ) {
        $cookie = str_replace('_lscache_vary=','',$cookie);
        if ( stripos($cookie, 'session') == FALSE ) {$cookie_sess = '';}
        if ( stripos($cookie, 'language') == FALSE ) {$cookie_lang = '';}
        if ( stripos($cookie, 'currency') == FALSE ) {$cookie_curr = '';}
        $cookies_lscache =  explode( ',', $cookie );
        foreach ( $cookies_lscache as $cookie_lscache ) {
                    if ( stripos($cookie_lscache, 'session') !== FALSE ) {$cookie_sess = $cookie_lscache;}
        if ( stripos($cookie_lscache, 'language') !== FALSE ) {$cookie_lang = $cookie_lscache;}
        if ( stripos($cookie_lscache, 'currency') !== FALSE ) {$cookie_curr = $cookie_lscache;}
        }
        $cookie = '';
     } else {
         	$cookie_sess = '';
			$cookie_lang = '';
			$cookie_curr = '';
     }

        $cookie_ua = '';
		$cookie_uap = '';
		$cookie_uab = '';
		$cookie_uas = '';
		if ( (stripos($userAgent, 'bot') !== FALSE) || (stripos($userAgent, 'compatible') !== FALSE) || (stripos($userAgent, 'headless') !== FALSE) || (stripos($userAgent, 'runner') !== FALSE) || (stripos($userAgent, 'walker') !== FALSE) ) {
            $cookie_ua = '';
			$cookie_uap = '';
			$cookie_uab = '';
			$cookie_uas = '';
			//$cookie_sess = '';
			//$cookie_lang = '';
			//$cookie_curr = '';
		} else {
            if ( (stripos($userAgent, 'OPR') !== FALSE) || (stripos($userAgent, 'OPT') !== FALSE) || (stripos($userAgent, 'Opera') !== FALSE) ) {
                //$cookie_uab = 'browser%3Aopera';
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'FxiOS') !== FALSE) || (stripos($userAgent, 'Firefox') !== FALSE) ) {
                //$cookie_uab = 'browser%3Afirefox';
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'Edg') !== FALSE) ) {
                //$cookie_uab = 'browser%3Aedge';
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'YaBrowser') !== FALSE) ) {
                //$cookie_uab = 'browser%3Ayandex';
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'Lighthouse') !== FALSE) ) {
                //elseif ( (stripos($userAgent, 'Lighthouse') !== FALSE) || (stripos($userAgent, 'Headless') !== FALSE) ) {
                //$cookie_uab = 'browser%3Alighthouse';
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'CriOS') !== FALSE) || (stripos($userAgent, 'Chrome') !== FALSE) ) {
                $cookie_uab = 'browser%3Achrome';
            } elseif ( (stripos($userAgent, 'Safari') !== FALSE) ) {
                    if ( (stripos($userAgent, 'Version/14') !== FALSE) || (stripos($userAgent, 'Version/15') !== FALSE) || (stripos($userAgent, 'Version/16') !== FALSE) || (stripos($userAgent, 'Version/17') !== FALSE) ) {
                    $cookie_uab = 'browser%3Achrome';
                    } else {
                    $cookie_uab = 'browser%3Asafari';
                    }
            } elseif ( (stripos($userAgent, 'Instagram') !== FALSE) ) {
                //$cookie_uab = 'browser%3Asafari';
                //$cookie_uab = 'browser%3Asafari';
                $cookie_uab = 'browser%3Achrome';
            } else {
                $cookie_uab = ''; //'browser%3Aunknown';
            }

            if (stripos($userAgent, 'Macintosh') !== FALSE) {
                $cookie_uap = 'apple%3Amacintosh';
            } elseif (stripos($userAgent, 'iPhone') !== FALSE) {
                $cookie_uap = 'apple%3Aiphone';
            } elseif (stripos($userAgent, 'iPad') !== FALSE) {
                $cookie_uap = 'apple%3Aipad';
            } else {
                $cookie_uap = '';
            }

            if (stripos($userAgent, 'Windows') !== FALSE) {
                $cookie_uas = 'os%3Awindows';
            } elseif (stripos($userAgent, 'Linux') !== FALSE) {
                $cookie_uas = 'os%3Alinux';
            } else {
                $cookie_uas = '';
            }

		    if (stripos($userAgent, 'iPhone') !== FALSE){
                $cookie_ua = 'device%3Amobile';
            } elseif (stripos($userAgent, 'iPad') !== FALSE){
			    $cookie_ua = 'device%3Atablet';
            } elseif ( (stripos($userAgent, 'Android') !== FALSE) && (stripos($userAgent, 'Chrome') !== FALSE) && (stripos($userAgent, 'Mobile') !== FALSE) ){
                $cookie_ua = 'device%3Amobile';
            } elseif ( (stripos($userAgent, 'Android') !== FALSE) && (stripos($userAgent, 'Chrome') !== FALSE) && (stripos($userAgent, 'Mobile') == FALSE) ){
                $cookie_ua = 'device%3Atablet';
            } else {
                $cookie_ua = '';
            }

		}

        //apple cookie
		if ( !( $cookie_uap == '') ) {
			if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_uap;
			} else {
				$cookie = '_lscache_vary=' . $cookie_uap;
			}
		}

        //browser cookie
		if ( !( $cookie_uab == '') ) {
			if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_uab;
			} else {
				$cookie = '_lscache_vary=' . $cookie_uab;
			}
		}

        //currency cookie
		if ( !( $cookie_curr == '') ) {
		    if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_curr;
			} else {
				$cookie = '_lscache_vary=' . $cookie_curr;
			}
		}

        //device cookie
		if ( !( $cookie_ua == '') ) {
		    if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_ua;
			} else {
				$cookie = '_lscache_vary=' . $cookie_ua;
			}
		}

        //language cookie
		if ( !( $cookie_lang == '') ) {
		    if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_lang;
			} else {
				$cookie = '_lscache_vary=' . $cookie_lang;
			}
		}

        //os cookie
		if ( !( $cookie_uas == '') ) {
			if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_uas;
			} else {
				$cookie = '_lscache_vary=' . $cookie_uas;
			}
		}

        //session cookie
		if ( !( $cookie_sess == '') ) {
		    if (stripos($cookie, '_lscache_vary') !== FALSE){
				//$cookie .= ',';
				$cookie .= '%2C';
				$cookie .= $cookie_sess;
			} else {
				$cookie = '_lscache_vary=' . $cookie_sess;
			}
		}

		return $cookie;

    }

    

}
