<?php
/**
 * Making Tax Digital for VAT
 * Oauth2 client and API Access
 * 
 * @author uzERP LLP and Steve Blamey <sblamey@uzerp.com>
 * @license GPLv3 or later
 * @copyright (c) 2020 uzERP LLP (support#uzerp.com). All rights reserved.
 *
 * uzERP is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

use \League\OAuth2\Client\Provider\GenericProvider;
use \League\OAuth2\Client\Token;

class MTD {

    private $provider;
    private $accessToken;
    private $base_url;
    private $fraud_protection_headers;
    private $vrn;
    private $config_key;
    private $logger;
    
    function __construct($config_key='mtd-vat') {
        $logger = uzLogger::Instance();
        // set log 'channel' for MTD log messages
        $this->logger = $logger->withName('uzerp_mtd');
        $this->config_key = $config_key;
        $company = DataObjectFactory::Factory('Systemcompany');
        $company->load(EGS_COMPANY_ID);
        $this->vrn = $company->getVRN();

        $oauth_config = OauthStorage::getconfig($this->config_key);
        
        $this->base_url = $oauth_config['baseurl'];
        $this->api_part = "/organisations/vat/{$this->vrn}";
        $this->provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $oauth_config['clientid'],
            'clientSecret'            => $oauth_config['clientsecret'],
            'scopes'                  => ['write:vat', 'read:vat'],
            'scopeSeparator'          => '+',
            'redirectUri'             => $oauth_config['redirecturl'],
            'urlAuthorize'            => "{$this->base_url}/oauth/authorize",
            'urlAccessToken'          => "{$this->base_url}/oauth/token",
            'urlResourceOwnerDetails' => "{$this->base_url}/organisations/vat" //required by the provider, but not impelemented by the API
        ]);

        $config = Config::Instance();
        $device_uuid = $oauth_config['clientuuid'];
        $current   = timezone_open('Europe/London');
        $utcTime  = new \DateTime('now', new \DateTimeZone('UTC'));
        $offsetInSecs =  $current->getOffset($utcTime);
        $hoursAndSec = gmdate('H:i', abs($offsetInSecs));
        $utc_offset = stripos($offsetInSecs, '-') === false ? "+{$hoursAndSec}" : "-{$hoursAndSec}";
        $os_info = rawurlencode(php_uname('s')) . '/' . rawurlencode(php_uname('r')) . ' (/)';
        $uz_user = constant('EGS_USERNAME');
        $uz_version = rawurlencode($config->get('SYSTEM_VERSION'));
        $local_ips = rawurlencode(getHostByName(getHostName()));
        // Generated mac address in case we don't get a list from the host, below.
        $local_macs = rawurlencode("9e:50:4f:9c:26:e1");
        
        // HMRC require:
        // 1. A list of all local IP addresses (IPv4 and IPv6) available to the originating device
        // 2. A list of MAC addresses available on the originating device
        //
        // These headers appear to mandatory and cannot have empty values,
        // see https://developer.service.hmrc.gov.uk/api-documentation/docs/fraud-prevention
        //
        // Next, we use run an 'ip' command to get the interfaces on the host in JSON format.
        // On Linux hosts, install the iproute2 package.
        //
        // If we fail to get information from 'ip' the above values in $local_ips and $local_macs
        // are used instead.

        $addr = [];
        $macs = [];

        $ifaces = json_decode(exec("ip -json addr"));
        if ($ifaces) {
            foreach($ifaces as $iface) {
                if (!in_array('LOOPBACK', $iface->flags)) {
                    $macs[] = rawurlencode($iface->address);
                    foreach($iface->addr_info as $ad) {
                        $addr[] = rawurlencode($ad->local);
                    }
                }
            }
        }

        if (!empty($addr)) {
            $local_ips = implode(',', $addr);
            $local_macs = implode(',', $macs);
        };

        $this->fraud_protection_headers = [
            'Gov-Client-Connection-Method' => 'OTHER_DIRECT',
            'Gov-Client-Device-ID' => $device_uuid,
            'Gov-Client-User-IDs' => "os={$uz_user}",
            'Gov-Client-Timezone' => "UTC{$utc_offset}",
            'Gov-Client-Local-IPs' => $local_ips,
            'Gov-Client-MAC-Addresses' => $local_macs,
            'Gov-Client-User-Agent' => $os_info,
            'Gov-Vendor-Version' => "uzerp={$uz_version}"
        ];

        $this->logger->info('Fraud prevention headers set', [$this->fraud_protection_headers]);
    }

    /**
     * Oauth2: Authorization Code Grant
     * 
     * Enables the user to authorise uzERP to access the Making Tax Digital VAT api
     * on behalf of the organisation
     * 
     * @see https://developer.service.hmrc.gov.uk/api-documentation/docs/authorisation/user-restricted-endpoints
     */
    function authorizationGrant() {
        // If we don't have an authorization code then get one
        if (!isset($_GET['code'])) {
        
            // Fetch the authorization URL from the provider; this returns the
            // urlAuthorize option and generates and applies any necessary parameters
            // (e.g. state).
            $authorizationUrl = $this->provider->getAuthorizationUrl();
        
            // Get the state generated for you and store it to the session.
            $_SESSION['oauth2state'] = $this->provider->getState();
        
            // Redirect the user to the authorization URL.
            header('Location: ' . $authorizationUrl);
            exit;
        
        // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || (isset($_SESSION['oauth2state']) && $_GET['state'] !== $_SESSION['oauth2state'])) {
        
            if (isset($_SESSION['oauth2state'])) {
                unset($_SESSION['oauth2state']);
            }
            
            exit('Oauth Error: Invalid state on authorization grant');
        
        } else {
            try
            {
                // Try to get an access token using the authorization code grant.
                $this->accessToken = $this->provider->getAccessToken('authorization_code', [
                    'code' => $_GET['code']
                ]);

                $storage = new OauthStorage();
                if (!$storage->storeToken($this->accessToken, $this->config_key)) {
                    exit('Oauth Error: failed to save access token after authorization grant');
                }
            }
            catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e)
            {
                // Failed to get the access token or user details.
                $response = $e->getResponseBody();
                exit("Oauth Error: {$response['error']}, {$response['error_description']}");
            }
        }
    }

    /**
     * Oauth2: Check and refresh Oauth2 Access Token
     * 
     * @see https://developer.service.hmrc.gov.uk/api-documentation/docs/authorisation/user-restricted-endpoints
     */
    function refreshToken() {
        $flash = Flash::Instance();
        $storage = new OauthStorage();
        $existingAccessToken = $storage->getToken($this->config_key);

        if ($existingAccessToken !== false) {
            if ($existingAccessToken->hasExpired()) {
                try
                {
                    $newAccessToken = $this->provider->getAccessToken('refresh_token', [
                        'refresh_token' => $existingAccessToken->getRefreshToken()
                    ]);

                    $storage->deleteToken($this->config_key);
                    $newStorage = new OauthStorage();
                    if (!$newStorage->storeToken($newAccessToken, $this->config_key)) {
                        $flash->addError("Oauth Error: Failed to store access token after refresh");
                        return false;
                    }
                }
                catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e)
                {
                    $response = $e->getResponseBody();
                    
                    // If authorization grant has expired, re-authorise application
                    if ($response['error'] == 'invalid_request') {
                        $storage->deleteToken($this->config_key);
                        $this->authorizationGrant();
                    }
                    $flash->addWarning("Oauth Error: {$response['error']}, {$response['error_description']}");
                    return false;
                }
            }
            return true;
        } else {
            $this->authorizationGrant();
        }
    }

    /**
     * API: Get VAT Obligations
     * 
     * @param array $qparams  Assoc array of query string paramters
     * @return array
     * 
     * @see https://developer.service.hmrc.gov.uk/api-documentation/docs/api/service/vat-api/1.0#_retrieve-vat-obligations_get_accordion
     */
    function getObligations($qparams) {
        $flash = Flash::Instance();

        $this->refreshToken();
        $storage = new OauthStorage();
        $accesstoken = $storage->getToken($this->config_key);
        if (!$accesstoken) {
            $this->authorizationGrant();
        }

        $query_string = '?';
        foreach ($qparams as $var => $qparam) {
            $query_string .= "{$var}=$qparam";
            if (next($qparams)) {
                $query_string .= '&' ;
            }
        }
        $endpoint = "{$this->base_url}{$this->api_part}/obligations";
        $url = $endpoint . $query_string;
        $request = $this->provider->getAuthenticatedRequest(
            'GET',
            $url,
            $accesstoken->getToken(),
            [
                'headers' => array_merge([
                'Accept' => 'application/vnd.hmrc.1.0+json',
                'Content-Type' => 'application/json'], $this->fraud_protection_headers)
            ]
        );

        try
        {
            $response = $this->provider->getResponse($request);
            return json_decode($response->getBody(), true);
        }
        catch (Exception $e)
        {
            $api_errors = json_decode($e->getResponse()->getBody()->getContents());
            if (count($api_errors) > 1) {
                foreach ($api_errors->errors as $error) {
                    $this->logger->error("{$error->code} {$error->message}", [__METHOD__]);
                    $flash->addError("{$error->code} {$error->message}");
                }
            } else {
                $this->logger->error("{$api_errors->code} {$api_errors->message}", [__METHOD__]);
                $flash->addError("{$api_errors->code} {$api_errors->message}");
            }
            return false;
        }
    }

    /**
     * API: Submit VAT Return
     * 
     * @param string $year
     * @param string $tax_period
     * @return boolean
     */
    function postVat($year, $tax_period) {
        $flash = Flash::Instance();
        $this->refreshToken();
        $storage = new OauthStorage();
        $accesstoken = $storage->getToken($this->config_key);
        if (!$accesstoken) {
            $this->authorizationGrant();
        }

        try {
            $return = new VatReturn;
            $return->loadVatReturn($year, $tax_period);
        }
        catch (VatReturnStorageException $e)
        {
            $this->logger->error($e->getMessage(), ['class_method' => __METHOD__]);
            $flash->addError($e->getMessage());
            return false;
        }
        
        // Use the collection becuase it has required info, like the period end date
        $returnc = new VatReturnCollection;
        $sh = new SearchHandler($returnc, false);
        $cc = new ConstraintChain();
        $cc->add(new Constraint('year', '=', $year));
        $cc->add(new Constraint('tax_period', '=', $tax_period));
        $cc->add(new Constraint('finalised', 'is', 'false'));
        $sh->addConstraintChain($cc);
        $returnc->load($sh);
        if(iterator_count($returnc) == 0){
            $flash->addError('No un-submitted return found');
            return false;
        }
        $returnc->rewind();

        // Find the matching obligation and get the HMRC period key
        $obligations = $this->getObligations(['status' => 'O']);
        if (!$obligations) {
            return false;
        }
        foreach ($obligations['obligations'] as $obligation) {
            if ($obligation['end'] == $returnc->current()->enddate) {
                try {
                    $return->setVatReturnPeriodKey($year, $tax_period, $obligation['periodKey']);
                } catch (VatReturnStorageException  $e) {
                    $this->logger->error($e->getMessage(), ['class_method' => __METHOD__]);
                    $flash->addError($e->getMessage());
                    $flash->addError("Failed to submit return for {$year}/{$tax_period}");
                    return false;
                }
                
                $body = [
                    'periodKey' => $obligation['periodKey'],
                    'vatDueSales' => round($return->vat_due_sales,2),
                    'vatDueAcquisitions' => round($return->vat_due_acquisitions,2),
                    'totalVatDue' => round($return->total_vat_due,2),
                    'vatReclaimedCurrPeriod' => round($return->vat_reclaimed_curr_period,2),
                    'netVatDue' => abs(round($return->net_vat_due,2)),
                    'totalValueSalesExVAT' => round($return->total_value_sales_ex_vat),
                    'totalValuePurchasesExVAT' => round($return->total_value_purchase_ex_vat),
                    'totalValueGoodsSuppliedExVAT' => round($return->total_value_goods_supplied_ex_vat),
                    'totalAcquisitionsExVAT' => round($return->total_acquisitions_ex_vat),
                    'finalised' => true
                ];

                $url = "{$this->base_url}{$this->api_part}/returns";
                $request = $this->provider->getAuthenticatedRequest(
                    'POST',
                    $url,
                    $accesstoken->getToken(),
                    [
                        'headers' => array_merge([
                        'Accept' => 'application/vnd.hmrc.1.0+json',
                        'Content-Type' => 'application/json'], $this->fraud_protection_headers),
                        'body' => json_encode($body),
                    ]
                );

                try
                {
                    $this->logger->info('Submitting VAT return', [
                        'vat_return_data' => $body,
                        'class_method' =>__METHOD__]);
                    $response = $this->provider->getResponse($request);
                    $rbody = json_decode($response->getBody(), true);
                    $rheader['Receipt-ID'] = $response->getHeader('Receipt-ID')[0];
                    $details = array_merge($rbody, $rheader);
                    $this->logger->info('VAT return submission response', [
                        'http_status' => $response->getStatusCode(),
                        'http_response_message' => $response->getReasonPhrase(),
                        'http_response_body' => $rbody,
                        'class_method' => __METHOD__]);
                    $return->saveSubmissionDetail($year, $tax_period, $details); //catch exception and log this info, it may fail to save
                    $flash->addMessage("VAT Return Submitted for {$year}/{$tax_period}");
                    return true;
                }
                catch (VatReturnStorageException $e)
                {
                    $this->logger->error('VAT return storage error', ['error_message' => $e->getMessage(), 'class_method' => __METHOD__]);
                    $flash->addError("VAT Return {$year}/{$tax_period} submitted, but not updated in uzERP");
                    return false;
                }
                catch (Exception $e)
                {
                    $api_errors = json_decode($e->getResponse()->getBody()->getContents());
                    foreach ($api_errors->errors as $error) {
                        $this->logger->error("HMRC API ERROR: {$error->code} {$error->message}", [
                            'http_status' => $e->getResponse()->getStatusCode(),
                            'class_method' => __METHOD__]);
                        $flash->addError("HMRC API ERROR: {$error->code} {$error->message}");
                    }
                    return false;
                }
            }
        }

        $flash->addWarning("No obligation found for the {$year}/{$tax_period} VAT period");
        return false;
    }
}
?>
