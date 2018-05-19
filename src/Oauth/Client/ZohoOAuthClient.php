<?php
namespace Zoho\OAuth\Client;

use Zoho\OAuth\Client\ZohoOAuth;
use Zoho\OAuth\Common\OAuthLogger;
use Zoho\OAuth\Common\ZohoOAuthHTTPConnector;
use Zoho\OAuth\Common\ZohoOAuthConstants;
use Zoho\OAuth\Common\ZohoOAuthTokens;
use Zoho\OAuth\Common\ZohoOAuthException;

class ZohoOAuthClient
{
    protected $zohoOAuthParams;
    protected static $zohoOAuthClient;
    
    private function __construct($params)
    {
        $this->zohoOAuthParams=$params;
    }
    public static function getInstance($params)
    {
        if (self::$zohoOAuthClient == null) {
            self::$zohoOAuthClient = new self($params);
        }
        return self::$zohoOAuthClient;
    }
    
    public static function getInstanceWithOutParam()
    {
        return self::$zohoOAuthClient;
    }
    
    public function getAccessToken($zuid)
    {
        $persistence = ZohoOAuth::getPersistenceHandlerInstance();
        $tokens;
        try {
            $tokens = $persistence->getOAuthTokens($zuid);
        } catch (ZohoOAuthException $ex) {
            OAuthLogger::severe("Exception while retrieving tokens from persistence - ".$ex);
            throw $ex;
        } catch (Exception $ex) {
            OAuthLogger::severe("Exception while retrieving tokens from persistence - ".$ex);
            throw new ZohoOAuthException($ex);
        }
        try {
            return $tokens->getAccessToken();
        } catch (ZohoOAuthException $ex) {
            OAuthLogger::info("Access Token has expired. Hence refreshing.");
            $tokens = self::refreshAccessToken($tokens->getRefreshToken(), $zuid);
            return $tokens->getAccessToken();
        }
    }
    
    public function generateAccessToken($grantToken)
    {
        if ($grantToken == null) {
            throw new ZohoOAuthException("Grant Token is not provided.");
        }
        try {
            $conn = self::getZohoConnector(ZohoOAuth::getTokenURL());
            $conn->addParam(ZohoOAuthConstants::GRANT_TYPE, ZohoOAuthConstants::GRANT_TYPE_AUTH_CODE);
            $conn->addParam(ZohoOAuthConstants::CODE, $grantToken);
            $resp = $conn->post();
            $responseJSON=self::processResponse($resp);
            if (array_key_exists(ZohoOAuthConstants::ACCESS_TOKEN, $responseJSON)) {
                $tokens = self::getTokensFromJSON($responseJSON);
                $tokens->setZUID(self::getUserZUIDFromIAM($tokens->getAccessToken()));

                ZohoOAuth::getPersistenceHandlerInstance()->saveOAuthData($tokens);
                return $tokens;
            } elseif (array_key_exists("error", $responseJSON)) {
                throw new ZohoOAuthException("Exception while fetching access token from grant token - ". $responseJSON['error']);
            } else {
                throw new ZohoOAuthException("Exception while fetching access token from grant token - " .$resp);
            }
        } catch (ZohoOAuthException $ex) {
            throw new ZohoOAuthException($ex);
        }
    }
    
    public function generateAccessTokenFromRefreshToken($refreshToken, $ZUID)
    {
        self::refreshAccessToken($refreshToken, $ZUID);
    }
    public function refreshAccessToken($refreshToken, $ZUID)
    {
        if ($refreshToken == null) {
            throw new ZohoOAuthException("Refresh token is not provided.");
        }
        try {
            $conn = self::getZohoConnector(ZohoOAuth::getRefreshTokenURL());
            $conn->addParam(ZohoOAuthConstants::GRANT_TYPE, ZohoOAuthConstants::GRANT_TYPE_REFRESH);
            $conn->addParam(ZohoOAuthConstants::REFRESH_TOKEN, $refreshToken);
            $response = $conn->post();
            $responseJSON=self::processResponse($response);
            if (array_key_exists(ZohoOAuthConstants::ACCESS_TOKEN, $responseJSON)) {
                $tokens = self::getTokensFromJSON($responseJSON);
                $tokens->setRefreshToken($refreshToken);
                $tokens->setZUID($ZUID);
                ZohoOAuth::getPersistenceHandlerInstance()->saveOAuthData($tokens);
                return $tokens;
            } else {
                throw new ZohoOAuthException("Exception while fetching access token from refresh token - " . $response);
            }
        } catch (ZohoOAuthException $ex) {
            throw new ZohoOAuthException($ex);
        }
    }
    
    private function getZohoConnector($url)
    {
        $zohoHttpCon = new ZohoOAuthHTTPConnector();
        $zohoHttpCon->setUrl($url);
        $zohoHttpCon->addParam(ZohoOAuthConstants::CLIENT_ID, $this->zohoOAuthParams->getClientId());
        $zohoHttpCon->addParam(ZohoOAuthConstants::CLIENT_SECRET, $this->zohoOAuthParams->getClientSecret());
        $zohoHttpCon->addParam(ZohoOAuthConstants::REDIRECT_URL, $this->zohoOAuthParams->getRedirectURL());
        return $zohoHttpCon;
    }
    
    private function getTokensFromJSON($responseObj)
    {
        $oAuthTokens = new ZohoOAuthTokens();
        $expiresIn = $responseObj[ZohoOAuthConstants::EXPIRES_IN];
        $oAuthTokens->setExpiryTime($oAuthTokens->getCurrentTimeInMillis()+$expiresIn);
    
        $accessToken = $responseObj[ZohoOAuthConstants::ACCESS_TOKEN];
        $oAuthTokens->setAccessToken($accessToken);
        if (array_key_exists(ZohoOAuthConstants::REFRESH_TOKEN, $responseObj)) {
            $refreshToken = $responseObj[ZohoOAuthConstants::REFRESH_TOKEN];
            $oAuthTokens->setRefreshToken($refreshToken);
        }
        return $oAuthTokens;
    }
    
    

    /**
     * zohoOAuthParams
     * @return unkown
     */
    public function getZohoOAuthParams()
    {
        return $this->zohoOAuthParams;
    }

    /**
     * zohoOAuthParams
     * @param unkown $zohoOAuthParams
     */
    public function setZohoOAuthParams($zohoOAuthParams)
    {
        $this->zohoOAuthParams = $zohoOAuthParams;
    }
    
    public function getUserZUIDFromIAM($accessToken)
    {
        $connector = new ZohoOAuthHTTPConnector();
        $connector->setUrl(ZohoOAuth::getUserInfoURL());
        $connector->addHeadder(ZohoOAuthConstants::AUTHORIZATION, ZohoOAuthConstants::OAUTH_HEADER_PREFIX.$accessToken);
        $apiResponse=$connector->get();
        $jsonResponse=self::processResponse($apiResponse);
        return $jsonResponse['ZUID'];
    }
    public function processResponse($apiResponse)
    {
        list($headers, $content) = explode("\r\n\r\n", $apiResponse, 2);
        $jsonResponse=json_decode($content, true);
        
        return $jsonResponse;
    }
}
