<?php

namespace LeanCloud\Engine;

use LeanCloud\LeanClient;
use LeanCloud\LeanUser;

class LeanEngine {

    /**
     * Allowed headers in a cross origin request (CORS)
     *
     * @var array
     */
    private static $allowedHeaders = array(
        'X-LC-Id', 'X-LC-Key', 'X-LC-Session', 'X-LC-Sign', 'X-LC-Prod',
        'X-Uluru-Application-Key',
        'X-Uluru-Application-Id',
        'X-Uluru-Application-Production',
        'X-Uluru-Client-Version',
        'X-Uluru-Session-Token',
        'X-AVOSCloud-Application-Key',
        'X-AVOSCloud-Application-Id',
        'X-AVOSCloud-Application-Production',
        'X-AVOSCloud-Client-Version',
        'X-AVOSCloud-Super-Key',
        'X-AVOSCloud-Session-Token',
        'X-AVOSCloud-Request-sign',
        'X-Requested-With',
        'Content-Type'
    );

    /**
     * Parsed LeanEngine env variables
     *
     * @var array
     */
    protected $env = array();

    /**
     * Get header value
     *
     * @param  string $key
     * @return string|null
     */
    protected function getHeaderLine($key) {
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        return null;
    }

    /**
     * Set header
     *
     * @param string $key Header key
     * @param string $val Header val
     * @return self
     */
    protected function withHeader($key, $val) {
        header("{$key}: {$val}");
        return $this;
    }

    /**
     * Send response with body and status code
     *
     * @param string $body   Response body
     * @param string $status Response status
     */
    protected function send($body, $status) {
        http_response_code($status);
        echo $body;
    }

    /**
     * Get request body string
     *
     * It reads body from `php://input`, which has cavets that it could be
     * read only once prior to php 5.6. Thus it is recommended to override
     * this method in subclass.
     *
     * @return string
     */
    protected function getBody() {
        $body = file_get_contents("php://input");
        return $body;
    }

    /**
     * Render data as JSON output and end request
     *
     * @param array $data
     */
    private function renderJSON($data=null, $status=200) {
        if (!is_null($data)) {
            $out = json_encode($data);
            $this->withHeader("Content-Type",
                              "application/json; charset=utf-8;")
                ->send($out, $status);
        }
        exit;
    }

    /**
     * Render error and end request
     *
     * @param string $message Error message
     * @param string $code    Error code
     * @param string $status  Http response status code
     */
    private function renderError($message, $code=1, $status=400) {
        $data = json_encode(array(
            "code"  => $code,
            "error" => $message
        ));
        $this->withHeader("Content-Type", "application/json; charset=utf-8;")
            ->send($data, $status);
        exit;
    }

    /**
     * Retrieve header value with multiple version of keys
     *
     * @param array $keys Keys in order
     * @retrun mixed
     */
    private function retrieveHeader($keys) {
        $val = null;
        forEach($keys as $k) {
            $val = $this->getHeaderLine($k);
            if (!empty($val)) {
                return $val;
            }
        }
        return $val;
    }

    /**
     * Extract variant headers into env
     */
    private function parseHeaders() {
        $this->env["ORIGIN"] = $this->retrieveHeader(array(
            "HTTP_ORIGIN"
        ));
        $this->env["CONTENT_TYPE"] = $this->retrieveHeader(array(
            "CONTENT_TYPE",
            "HTTP_CONTENT_TYPE"
        ));
        $this->env["REMOTE_ADDR"] = $this->retrieveHeader(array(
            "HTTP_X_REAL_IP",
            "HTTP_X_FORWARDED_FOR",
            "REMOTE_ADDR"
        ));

        $this->env["LC_ID"] = $this->retrieveHeader(array(
            "HTTP_X_LC_ID",
            "HTTP_X_AVOSCLOUD_APPLICATION_ID",
            "HTTP_X_ULURU_APPLICATION_ID"
        ));
        $this->env["LC_KEY"] = $this->retrieveHeader(array(
            "HTTP_X_LC_KEY",
            "HTTP_X_AVOSCLOUD_APPLICATION_KEY",
            "HTTP_X_ULURU_APPLICATION_KEY"
        ));
        $this->env["LC_MASTER_KEY"] = $this->retrieveHeader(array(
            "HTTP_X_AVOSCLOUD_MASTER_KEY",
            "HTTP_X_ULURU_MASTER_KEY"
        ));
        $this->env["LC_SESSION"] = $this->retrieveHeader(array(
            "HTTP_X_LC_SESSION",
            "HTTP_X_AVOSCLOUD_SESSION_TOKEN",
            "HTTP_X_ULURU_SESSION_TOKEN"
        ));
        $this->env["LC_SIGN"] = $this->retrieveHeader(array(
            "HTTP_X_LC_SIGN",
            "HTTP_X_AVOSCLOUD_REQUEST_SIGN"
        ));
        $prod = $this->retrieveHeader(array(
            "HTTP_X_LC_PROD",
            "HTTP_X_AVOSCLOUD_APPLICATION_PRODUCTION",
            "HTTP_X_ULURU_APPLICATION_PRODUCTION"
        ));
        $this->env["useProd"] = true;
        if ($prod === 0 || $prod === false) {
            $this->env["useProd"] = false;
        }
        $this->env["useMaster"] = false;
    }

    /**
     * Parse plain text body
     *
     * The CORS request might be sent as POST request with text/plain
     * header, whence the app key info is attached in the body as
     * JSON.
     *
     * @param string $body
     * @return array Decoded body array
     */
    private function parsePlainBody($body) {
        $data = json_decode($body, true);
        if (!empty($data)) {
            $this->env["LC_ID"]         = isset($data["_ApplicationId"]) ?
                                          $data["_ApplicationId"] : null;
            $this->env["LC_KEY"]        = isset($data["_ApplicationKey"]) ?
                                          $data["_ApplicationKey"] : null;
            $this->env["LC_MASTER_KEY"] = isset($data["_MasterKey"]) ?
                                          $data["_MasterKey"] : null;
            $this->env["LC_SESSION"]    = isset($data["_SessionToken"]) ?
                                          $data["_SessionToken"] : null;
            $this->env["LC_SIGN"]       = null;
            $this->env["useProd"] = isset($data["_ApplicationProduction"]) ?
                                  (true && $data["_ApplicationProduction"]) :
                                  true;
            $this->env["useMaster"] = false;
            // remove internal fields set by API
            forEach($data as $key) {
                if ($key[0] === "_") {
                    unset($data[$key]);
                }
            }
        }
        return $data;
    }

    /**
     * Authenticate request by app ID and key
     */
    private function authRequest() {
        $appId = $this->env["LC_ID"];
        $sign  = $this->env["LC_SIGN"];
        if ($sign && LeanClient::verifySign($appId, $sign)) {
            if (strpos($sign, "master") !== false) {
                $this->env["useMaster"] = true;
            }
            return true;
        }

        $appKey = $this->env["LC_KEY"];
        if ($appKey && LeanClient::verifyKey($appId, $appKey)) {
            if (strpos($appKey, "master") !== false) {
                $this->env["useMaster"] = true;
            }
            return true;
        }

        $masterKey = $this->env["LC_MASTER_KEY"];
        $key = "{$masterKey}, master";
        if ($masterKey && LeanClient::verifyKey($appId, $key)) {
            $this->env["useMaster"] = true;
            return true;
        }

        $this->renderError("Unauthorized", 401, 401);
    }

    /**
     * Set user session if sessionToken present
     */
    private function processSession() {
        $token = $this->env["LC_SESSION"];
        if ($token) {
            LeanUser::become($token);
        }
    }

    /**
     * Dispatch request
     *
     * Following routes are processed and returned by LeanEngine:
     *
     * ```
     * OPTIONS {1,1.1}/{functions,call}.*
     * *       __engine/1/ping
     * *      {1,1.1}/{functions,call}/_ops/metadatas
     * *      {1,1.1}/{functions,call}/onVerified/{sms,email}
     * *      {1,1.1}/{functions,call}/BigQuery/onComplete
     * *      {1,1.1}/{functions,call}/{className}/{hookName}
     * *      {1,1.1}/{functions,call}/{funcName}
     * ```
     *
     * others may be added in future.
     *
     * @param string $method Request method
     * @param string $url    Request url
     */
    protected function dispatch($method, $url) {
        $path = parse_url($url, PHP_URL_PATH);
        $path = rtrim($path, "/");
        if (strpos($path, "/__engine/1/ping") === 0) {
            $this->renderJSON(array(
                "runtime" => "php-" . phpversion(),
                "version" => LeanClient::VERSION
            ));
        }

        $this->parseHeaders();

        $pathParts = array(); // matched path components
        if (preg_match("/^\/(1|1\.1)\/(functions|call)(.*)/",
                       $path,
                       $pathParts) === 1) {
            $pathParts["version"]  = $pathParts[1]; // 1 or 1.1
            $pathParts["endpoint"] = $pathParts[2]; // functions or call
            $pathParts["extra"]    = $pathParts[3]; // extra part after endpoint
            $origin = $this->env["ORIGIN"];
            $this->withHeader("Access-Control-Allow-Origin",
                              $origin ? $origin : "*");
            if ($method == "OPTIONS") {
                $this->withHeader("Access-Control-Max-Age", 86400)
                    ->withHeader("Access-Control-Allow-Methods",
                                 "PUT, GET, POST, DELETE, OPTIONS")
                    ->withHeader("Access-Control-Allow-Headers",
                                 implode(", ", self::$allowedHeaders))
                    ->withHeader("Content-Length", 0)
                    ->renderJSON();
            }

            $body = $this->getBody();
            if (preg_match("/text\/plain/", $this->env["CONTENT_TYPE"])) {
                // To work around with CORS restriction, some requests are
                // submit as text/palin body, where headers are attached
                // in the body.
                $json = $this->parsePlainBody($body);
            } else {
                $json = json_decode($body, true);
            }

            $this->authRequest();
            $this->processSession();
            if (strpos($pathParts["extra"], "/_ops/metadatas") === 0) {
                if ($this->env["useMaster"]) {
                    $this->renderJSON(Cloud::getKeys());
                } else {
                    $this->renderError("Unauthorized.", 401, 401);
                }
            }

            // extract func params from path:
            // /1.1/call/{0}/{1}
            $funcParams = explode("/", ltrim($pathParts["extra"], "/"));
            if (count($funcParams) == 1) {
                // {1,1.1}/functions/{funcName}
                $this->dispatchFunc($funcParams[0], $json,
                                    $pathParts["endpoint"] === "call");
            } else {
                if ($funcParams[0] == "onVerified") {
                    // {1,1.1}/functions/onVerified/sms
                    $this->dispatchOnVerified($funcParams[1], $json);
                } else if ($funcParams[0] == "_User" &&
                           $funcParams[1] == "onLogin") {
                    // {1,1.1}/functions/_User/onLogin
                    $this->dispatchOnLogin($json);
                } else if ($funcParams[0] == "BigQuery" ||
                           $funcParams[0] == "Insight") {
                    // {1,1.1}/functions/Insight/onComplete
                    $this->dispatchOnInsight($json);
                } else if (count($funcParams) == 2) {
                    // {1,1.1}/functions/{className}/beforeSave
                    $this->dispatchHook($funcParams[0], $funcParams[1], $json);
                }
            }
        }
    }

    /**
     * Dispatch function and render result
     *
     * @param string $funcName Function name
     * @param array  $body     JSON decoded body params
     * @param bool   $decodeObj
     */
    private function dispatchFunc($funcName, $body, $decodeObj=false) {
        $params = $body;
        if ($decodeObj) {
            $params = LeanClient::decode($body, null);
        }
        $meta["remoteAddress"] = $this->env["REMOTE_ADDR"];
        try {
            $result = Cloud::run($funcName,
                                 $params,
                                 LeanUser::getCurrentUser(),
                                 $meta);
        } catch (FunctionError $err) {
            $this->renderError($err->getMessage(), $err->getCode());
        }
        if ($decodeObj) {
            // Encode object to full, type-annotated JSON
            $out = LeanClient::encode($result, "toFullJSON");
        } else {
            // Encode object to type-less literal JSON
            $out = LeanClient::encode($result, "toJSON");
        }
        $this->renderJSON(array("result" => $out));
    }

    /**
     * Dispatch class hook and render result
     *
     * @param string $className
     * @param string $hookName
     * @param array  $body     JSON decoded body params
     */
    private function dispatchHook($className, $hookName, $body) {
        $json              = $body["object"];
        $json["__type"]    = "Object";
        $json["className"] = $className;
        $obj = LeanClient::decode($json, null);

        // set hook marks to prevent infinite loop. For example if user
        // invokes `$obj->save` in an afterSave hook, API will not again
        // invoke afterSave if we set hook marks.
        forEach(array("__before", "__after", "__after_update") as $key) {
            if (isset($json[$key])) {
                $obj->set($key, $json[$key]);
            }
        }

        // in beforeUpdate hook, attach updatedKeys to object so user
        // can detect changed keys in hook.
        if (isset($json["_updatedKeys"])) {
            $obj->updatedKeys = $json["_updatedKeys"];
        }

        $meta["remoteAddress"] = $this->env["REMOTE_ADDR"];
        try {
            $result = Cloud::runHook($className,
                                     $hookName,
                                     $obj,
                                     LeanUser::getCurrentUser(),
                                     $meta);
        } catch (FunctionError $err) {
            $this->renderError($err->getMessage(), $err->getCode());
        }
        if ($hookName == "beforeDelete") {
            $this->renderJSON(array());
        } else if (strpos($hookName, "after") === 0) {
            $this->renderJSON(array("result" => "ok"));
        } else {
            $outObj = $result;
            // Encode result object to type-less literal JSON
            $this->renderJSON($outObj->toJSON());
        }
    }

    /**
     * Dispatch onVerified hook
     *
     * @param string $type Verify type: email or sms
     * @param array  $body JSON decoded body params
     */
    private function dispatchOnVerified($type, $body) {
        $userObj = LeanClient::decode($body["object"], null);
        LeanUser::saveCurrentUser($userObj);
        $meta["remoteAddress"] = $this->env["REMOTE_ADDR"];
        try {
            Cloud::runOnVerified($type, $userObj, $meta);
        } catch (FunctionError $err) {
            $this->renderError($err->getMessage(), $err->getCode());
        }
        $this->renderJSON(array("result" => "ok"));
    }

    /**
     * Dispatch onLogin hook
     *
     * @param array $body JSON decoded body params
     */
    private function dispatchOnLogin($body) {
        $userObj = LeanClient::decode($body["object"], null);
        $meta["remoteAddress"] = $this->env["REMOTE_ADDR"];
        try {
            Cloud::runOnLogin($userObj, $meta);
        } catch (FunctionError $err) {
            $this->renderError($err->getMessage(), $err->getCode());
        }
        $this->renderJSON(array("result" => "ok"));
    }

    /**
     * Dispatch onInsight hook
     *
     * @param array $body JSON decoded body params
     */
    private function dispatchOnInsight($body) {
        $meta["remoteAddress"] = $this->env["REMOTE_ADDR"];
        try {
            Cloud::runOnInsight($body, $meta);
        } catch (FunctionError $err) {
            $this->renderError($err->getMessage(), $err->getCode());
        }
        $this->renderJSON(array("result" => "ok"));
    }

    /**
     * Start engine and process request
     */
    public function start() {
        $this->dispatch($_SERVER["REQUEST_METHOD"],
                        $_SERVER["REQUEST_URI"]);
    }

}

