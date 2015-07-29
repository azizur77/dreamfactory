<?php

namespace Dreamfactory\Http\Middleware;

use Closure;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\TooManyRequestsException;
use DreamFactory\Core\Utility\Enterprise;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use Illuminate\Contracts\Routing\Middleware;
use Illuminate\Routing\Router;

class Limits
{

    private $_inUnitTest = false;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // Get limits
        $limits = Enterprise::getPolicyLimits();

        if (is_null($limits) === false && is_null($this->_getServiceName()) === false) {

            $this->_inUnitTest = \Config::get('api_limits_test');

            $userName = $this->_getUser(Session::getCurrentUserId());
            $userRole = $this->_getRole(Session::getRoleId());
            $apiName = $this->_getApiKey(Session::getApiKey());
            $serviceName = $this->_getServiceName();

            // Build the list of API Hits to check

            $apiKeysToCheck = array('default' => 0);

            $serviceKeys[$serviceName] = 0;
            if (is_null($userRole) === false) {
                $serviceKeys[$serviceName . '.' . $userRole] = 0;
            }
            if (is_null($userName) === false) {
                $serviceKeys[$serviceName . '.' . $userName] = 0;
            }

            if (is_null($apiName) === false) {
                $apiKeysToCheck[$apiName] = 0;
                if (is_null($userRole) === false) {
                    $apiKeysToCheck[$apiName . '.' . $userRole] = 0;
                }
                if (is_null($userName) === false) {
                    $apiKeysToCheck[$apiName . '.' . $userName] = 0;
                }

                foreach ($serviceKeys as $key => $value) {
                    $apiKeysToCheck[$apiName . '.' . $key] = $value;
                }
            }

            if (is_null($userName) === false) {
                $apiKeysToCheck[$userName] = 0;
            }

            if (is_null($userRole) === false) {
                $apiKeysToCheck[$userRole] = 0;
            }

            $apiKeysToCheck = array_merge($apiKeysToCheck, $serviceKeys);

            $overLimit = false;
            try {
                foreach ($limits['api'] as $key => $limit) {
                    if (array_key_exists($key, $apiKeysToCheck) === true) {

                        $cacheValue = \Cache::get($key, 0);
                        $cacheValue++;
                        \Cache::put($key, $cacheValue, $limit['period']);
                        if ($cacheValue > $limit['limit']) {
                            $overLimit = true;
                        }
                    }
                }
            } catch ( \Exception $e ) {
                return ResponseFactory::getException(
                    new InternalServerErrorException('Unable to update cache'),
                    $request
                );
            }

            if ($overLimit === true) {
                return ResponseFactory::getException(
                    new TooManyRequestsException('Specified connection limit exceeded'),
                    $request
                );
            }
        }

        return $next($request);
    }

    /**
     * Return the User ID from the authenticated session prepended with user_ or null if there is no authenticated user
     *
     * @param $userId
     *
     * @return null|string
     */
    private function _getUser($userId)
    {
        if ($this->_inUnitTest === true) {
            return 'user_1';
        } else {
            return is_null($userId) === false ? 'user_' . $userId : null;
        }
    }

    /**
     * Return the Role ID from the authenticated session prepended with role_ or null if there is no authenticated user
     * or the user has no roles assigned
     *
     * @param $roleId
     *
     * @return null|string
     */
    private function _getRole($roleId)
    {
        if ($this->_inUnitTest === true) {
            return 'role_2';
        } else {
            return is_null($roleId) === false ? 'role_' . $roleId : null;
        }
    }

    /**
     * Return the API Key if set or null
     *
     * @param $apiKey
     *
     * @return null|string
     */

    private function _getApiKey($apiKey)
    {
        if ($this->_inUnitTest === true) {
            return 'apiName';
        } else {
            return is_null($apiKey) === false ? 'key_' . $apiKey : null;
        }
    }

    /**
     * Return the service name.  May return null if a list of services has been requested
     *
     * @return null|string
     */

    private function _getServiceName()
    {
        if ($this->_inUnitTest === true) {
            return 'serviceName';
        } else {
            /** @var Router $router */
            $router = app('router');
            $service = strtolower($router->input('service'));

            if (is_null($service) === true ) {
                return null;
            } else {
                return 'svc_' . $service;
            }

        }
    }
}
