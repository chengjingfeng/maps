<?php
/**
 * Nextcloud - maps
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Maps\Controller;

use OCP\App\IAppManager;

use OCP\IURLGenerator;
use OCP\IConfig;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

class UtilsController extends Controller {


    private $userId;
    private $config;
    private $dbconnection;
    private $dbtype;

    public function __construct($AppName, IRequest $request, $UserId,
        $userfolder, $config, IAppManager $appManager){
        parent::__construct($AppName, $request);
        $this->userId = $UserId;
        // IConfig object
        $this->config = $config;
        $this->dbconnection = \OC::$server->getDatabaseConnection();
    }

    /**
     * Delete user options
     * @NoAdminRequired
     */
    public function deleteOptionsValues() {
        $keys = $this->config->getUserKeys($this->userId, 'maps');
        foreach ($keys as $key) {
            $this->config->deleteUserValue($this->userId, 'maps', $key);
        }
        return new DataResponse(['done'=>1]);
    }

    /**
     * Save options values to the DB for current user
     * @NoAdminRequired
     */
    public function saveOptionValue($options) {
        foreach ($options as $key => $value) {
            $this->config->setUserValue($this->userId, 'maps', $key, $value);
        }
        return new DataResponse(['done'=>1]);
    }

    /**
     * get options values from the config for current user
     * @NoAdminRequired
     */
    public function getOptionsValues() {
        $ov = array();
        $keys = $this->config->getUserKeys($this->userId, 'maps');
        foreach ($keys as $key) {
            $value = $this->config->getUserValue($this->userId, 'maps', $key);
            $ov[$key] = $value;
        }
        return new DataResponse(['values'=>$ov]);
    }

}
