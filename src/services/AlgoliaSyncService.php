<?php
/**
 * Algolia Sync plugin for Craft CMS 3.x
 *
 * Syncing elements with Algolia using their API
 *
 * @link      https://www.brilliancenw.com/
 * @copyright Copyright (c) 2018 Mark Middleton
 */

namespace brilliance\algoliasync\services;

use brilliance\algoliasync\AlgoliaSync;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use craft\elements\User;


use brilliance\algoliasync\events\beforeAlgoliaSyncEvent;

use brilliance\algoliasync\jobs\AlgoliaSyncTask;

/**
 * AlgoliaSyncService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Mark Middleton
 * @package   AlgoliaSync
 * @since     1.0.0
 */
class AlgoliaSyncService extends Component
{
    // Public Methods
    // =========================================================================

    const EVENT_BEFORE_ALGOLIA_SYNC = 'beforeAlgoliaSyncEvent';

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     AlgoliaSync::$plugin->algoliaSyncService->exampleService()
     *
     * @return mixed
     */

    public function generateSecuredApiKey($filterCompany=null) {

        $algoliaConfig = [];

        $validUntil = time() + (60 * 60 * 24);

        $algoliaConfig['validUntil'] = $validUntil;

        if (isset($filterCompany) && $filterCompany > 0) {
            $algoliaConfig['filters'] = 'company_'.$filterCompany;
        }
        $public_key = \AlgoliaSearch\Client::generateSecuredApiKey(
            AlgoliaSync::$plugin->settings->getAlgoliaSearch(),
            $algoliaConfig
        );

        if (isset($public_key)) {
            return $public_key;
        }
        else {
            return '';
        }
    }

    public function updateAllElements($elementType, $sectionId) {

        SWITCH ($elementType) {
            CASE 'entry':
                $entries = Entry::find()->sectionId($sectionId)->all();
                foreach ($entries AS $entry) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($entry);
                }
                break;

            CASE 'category':

                $categories = Category::find()->groupId($sectionId)->all();
                foreach ($categories AS $cat) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($cat);
                }
                break;

            CASE 'user':
                $allUsers = User::find()->groupId($sectionId)->all();
                foreach ($allUsers AS $user) {
                    AlgoliaSync::$plugin->algoliaSyncService->prepareAlgoliaSyncElement($user);
                }
                break;
        }
    }
    // is this element one that is configured to be synced with Algolia?
    public function algoliaElementSynced($element) {

        $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
        $algoliaSettings = AlgoliaSync::$plugin->getSettings();

        SWITCH ($elementInfo['type']) {
            CASE 'entry':
                $entrySections = $elementInfo['sectionId'];
                $syncedSections = $algoliaSettings['algoliaSections'];

                if (!is_array($syncedSections)) {
                    return false;
                }
                foreach ($entrySections AS $syncSection) {
                    if (in_array($syncSection, $syncedSections)) {
                        return true;
                    }
                }
            break;

            CASE 'category':

                $categoryGroups = $elementInfo['sectionId'];

                if (is_array($algoliaSettings['algoliaCategories'])) {
                    $syncedCategories = $algoliaSettings['algoliaCategories'];
                }
                else {
                    $syncedCategories = [];
                }

                foreach ($categoryGroups AS $groupId) {
                    if (in_array($groupId, $syncedCategories)) {
                        return true;
                    }
                }
            break;

            CASE 'user':
                $userGroups = $elementInfo['sectionId'];
                $syncedGroups = $algoliaSettings['algoliaUserGroupList'];

                if (is_array($syncedGroups) AND count($syncedGroups) > 0) {
                    foreach ($userGroups AS $groupId) {
                        if (in_array($groupId, $syncedGroups)) {
                            return true;
                        }
                    }
                }
            break;
        }
        return false;
    }


    public function algoliaSyncRecord($action, $recordUpdate, $queueMessage = '') {

        $message = "[".$action."] Algolia Sync record to queue with the following data:\n";
        $message .= print_r($recordUpdate['index'], true);
        $message .= print_r($recordUpdate, true);

        $file = Craft::getAlias('@storage/logs/algoliasync.log');
        $log = date('Y-m-d H:i:s').' '.$message."\n";
        craft\helpers\FileHelper::writeToFile($file, $log, ['append' => true]);

        Craft::warning($message, __METHOD__);

        $queue = Craft::$app->getQueue();
        $queue->push(new AlgoliaSyncTask([
            'algoliaIndex' => $recordUpdate['index'],
            'algoliaFunction' => $action,
            'algoliaObjectID' => $recordUpdate['attributes']['objectID'],
            'algoliaRecord' => $recordUpdate['attributes'],
            'algoliaMessage' => $queueMessage
        ]));

    }

    public function algoliaResetIndex($index) {

        $queue = Craft::$app->getQueue();
        $queue->push(new AlgoliaResetTask([
            'algoliaIndex' => $index
        ]));

    }

    public function stopwordPass($fieldHandle, $stopword = '') {
        $stopword = trim($stopword);
        if ($stopword === '') {
            return true;
        }
        else {
            $stopword = strtolower($stopword);
            if (strpos($fieldHandle, $stopword) === 0) {
                return false;
            }
        }
        return true;
    }

    public function getFieldData($element, $field, $fieldHandle) {
        // $event->element

        $fieldTypeLong = get_class($field);
        $fieldTypeArray = explode('\\', $fieldTypeLong);
        $fieldType = strtolower(array_pop($fieldTypeArray));

        /*
         *
         * TODO - instead of the custom code for hooplarelated records...
         * TODO - we should have an event that calls out with the field type
         * TODO - and gets responses from other plugins
         * TODO - This isn't harmful for now...
         * TODO - just a bit cumbersome and adds code that won't apply to any other project
         *
         */

        $file = Craft::getAlias('@storage/logs/algoliasync.log');
        $log = date('Y-m-d H:i:s').' extracting info from this handle '.$fieldHandle.': '.$fieldType."\n";

        craft\helpers\FileHelper::writeToFile($file, $log, ['append' => true]);
        if ($fieldType == 'assets') {
            // $dump = print_r($element->$fieldHandle->getOptions(), true);
            $thisAsset = $element->$fieldHandle->one();

            if ($thisAsset) {
                $log = " ---------------------- ASSET ---------------------\n";
                $log = $log . print_r($thisAsset->url, true)."\n";
                $log = $log . " ---------------------- END ASSET ---------------------\n";
                craft\helpers\FileHelper::writeToFile($file, $log, ['append' => true]);
            }

        }


        switch ($fieldType) {
            case 'plaintext':
                $checkValue = $element->$fieldHandle;
                if (is_numeric($checkValue)) {
                    return (float)$element->$fieldHandle;
                    }
                return $element->$fieldHandle;
                break;
            case 'categories':
                $categories = $element->$fieldHandle->all();
                $returnCats = [];
                foreach ($categories AS $cat) {
                    $returnCats[] = $cat->title;
                }
                return $returnCats;
                break;
            case 'entries':

                // we should add an ID field, as well as the title field
                $allEntries = $element->$fieldHandle->all();

                $titlesArray = [];
                $idsArray = [];

                foreach ($allEntries AS $thisEntry) {
                    $titlesArray[] = $thisEntry->title;
                    $idsArray[] = $thisEntry->id;
                }
                return array(
                    'type' => 'entries',
                    'ids'   => $idsArray,
                    'titles'    => $titlesArray
                );

                break;
            case 'number':
                    return (float)$element->$fieldHandle;
                break;
            case 'lightswitch':
                    return (bool)$element->$fieldHandle;
                break;
            case 'multiselect':
            case 'checkboxes':
                $storedOptions = [];
                $fieldOptions = $element->$fieldHandle->getOptions();
                if ($fieldOptions) {
                    foreach ($fieldOptions AS $option) {
                        if ($option->selected) {
                            $storedOptions[] = $option->label;
                        }
                    }
                }
                return $storedOptions;
                break;
            case 'dropdown':
                    return $element->$fieldHandle->label;
                break;
            case 'date':
                if ($element->$fieldHandle) {
                    return $element->$fieldHandle->getTimestamp();
                }
                else {
                    return null;
                }
                break;
            case 'assets':
                $thisAsset = $element->$fieldHandle->one();
                if ($thisAsset) {
                    return $thisAsset->url;
                }
                else {
                    return null;
                }
                break;
            case 'mapfield':
//                print "latitude:";
//                print $element->$fieldHandle->lat;
//                exit;
                return null;
                break;

        }
        return '';

    }

    public function prepareAlgoliaSyncElement($element, $action = 'save', $algoliaMessage = null) {

        // do we update this type of element?
        $recordUpdate = array();

        $algoliaSettings = AlgoliaSync::$plugin->getSettings();
        $algoliaStopWord = $algoliaSettings['algoliaStopWord'];

        if (AlgoliaSync::$plugin->algoliaSyncService->algoliaElementSynced($element)) {

            // what type of element
            // user, entry, category
            $recordUpdate['attributes'] = array();

            if ($action == 'delete') {
                $algoliaAction = 'delete';
            } else {
                if ($element->enabled) {
                    $algoliaAction = 'insert';
                } else {
                    $algoliaAction = 'delete';
                }
            }

            // get the attributes of the entity
            $recordUpdate['attributes']['objectID'] = (int)$element->id;
            $recordUpdate['attributes']['message'] = (int)$element->id;

            if (isset($element->slug)) {
                $recordUpdate['attributes']['slug'] = $element->slug;
            }
            if (isset($element->authorId)) {
                $recordUpdate['attributes']['authorId'] = (int)$element->authorId;
            }
            if (isset($element->postDate)) {
                $recordUpdate['attributes']['postDate'] = (int)$element->postDate->getTimestamp();
            }

            $fields = $element->getFieldLayout()->getFields();

            foreach ($fields AS $field) {

                $fieldHandle = $field->handle;

                if ($algoliaStopWord === '' OR $this->stopwordPass($fieldHandle, $algoliaStopWord)) {

                    // send this off to a function to extract the specific information
                    // based on what type of field it is (asset, text, varchar, etc...)

                    $fieldName = AlgoliaSync::$plugin->algoliaSyncService->sanitizeFieldName($field->name);

                    $rawData = AlgoliaSync::$plugin->algoliaSyncService->getFieldData($element, $field, $fieldHandle);

                    if (isset($rawData['type']) && $rawData['type'] == 'entries') {
                        $recordUpdate['attributes'][$fieldName] = $rawData['titles'];
                        $idsFieldName = $fieldName.'Ids';
                        $recordUpdate['attributes'][$idsFieldName] = $rawData['ids'];
                    }
                    else {
                        $recordUpdate['attributes'][$fieldName] = $rawData;
                    }

                    $fieldTypeLong = get_class($field);
                    $fieldTypeArray = explode('\\', $fieldTypeLong);
                    $fieldType = strtolower(array_pop($fieldTypeArray));

                    if ($fieldType == 'date') {
                        // get the friendly date
                        $friendlyName = $fieldName . "_friendly";
                        $friendlyDate = date('n/j/Y', $rawData);
                        $recordUpdate['attributes'][$friendlyName] = $friendlyDate;

                        // get the previous midnight of the current date (unix timestamp)
                        $midnightName = $fieldName . "_midnight";
                        $midnightTimestamp = mktime(0, 0, 0, date('n', $rawData), date('j', $rawData), date('Y', $rawData));
                        $recordUpdate['attributes'][$midnightName] = $midnightTimestamp;
                    }
                }
            }

            $recordUpdate['index'] = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);

            $elementInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element);
            $elementTypeSlug = $elementInfo['type'];

            switch ($elementTypeSlug) {
                case 'category':
                    $recordUpdate['elementType'] = 'Category';
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
                    $recordUpdate['attributes']['title'] = $element->title;
                    break;

                case 'entry':
                    $recordUpdate['elementType'] = 'Entry';
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
                    $recordUpdate['attributes']['title'] = $element->title;
                    break;

                case 'user':
                    $recordUpdate['elementType'] = 'User';
                    $recordUpdate['handle'] = $elementInfo['sectionHandle'];
                    $recordUpdate['attributes']['title'] = $element->username;
                    $recordUpdate['attributes']['firstname'] = $element->firstName;
                    $recordUpdate['attributes']['lastname'] = $element->lastName;
                    $recordUpdate['attributes']['email'] = $element->email;
                    $userGroups = $element->getGroups();
                    $groupList = [];
                    foreach ($userGroups AS $group) {
                        $groupList[] = $group->handle;
                    }
                    $recordUpdate['attributes']['userGroups'] = $groupList;
                    break;
            }

            // Fire event for tracking before the sync event.
            $event = new beforeAlgoliaSyncEvent([
                'recordElement' => $element,
                'recordUpdate' => $recordUpdate
            ]);

            $this->trigger(self::EVENT_BEFORE_ALGOLIA_SYNC, $event);
            $recordUpdate = $event->recordUpdate;

            AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord($algoliaAction, $recordUpdate, $algoliaMessage);
        }
    }

    public function sanitizeFieldName($fieldName) {
        $fieldName = preg_replace("/[^A-Za-z0-9 ]/", '', $fieldName);
        return str_replace(' ', '_', $fieldName);
    }
    public function getEventElementInfo($element, $processRecords = true) {

        $elementTypeSlugArray = explode("\\", get_class($element));

        $info = [];
        $info['type'] = strtolower(array_pop($elementTypeSlugArray));
        $info['id'] = $element->id;

        $info['sectionHandle'] = [];
        $info['sectionId'] = [];

        switch ($info['type']) {
            case 'category':
                $info['sectionHandle'][] = Craft::$app->categories->getGroupById($element->groupId)->handle;
                $info['sectionId'][] = $element->groupId;
                break;
            case 'entry':
                if (!empty($element->sectionId)) {
                    $info['sectionHandle'][] = Craft::$app->sections->getSectionById($element->sectionId)->handle;
                    $info['sectionId'][] = $element->sectionId;
                }
                break;
            case 'user':

                // this will get the current user's groups
                // lets only upsert records that match the configured groups in algolia sync
                $algoliaSettings = AlgoliaSync::$plugin->getSettings();
                $syncedGroups = $algoliaSettings['algoliaUserGroupList'];

                $userGroups = Craft::$app->userGroups->getGroupsByUserId($element->id);
                $deleteFromAlgolia = true;

                foreach ($userGroups AS $group) {
                    if (in_array($group->id, $syncedGroups)) {
                        $info['sectionHandle'][] = $group->handle;
                        $info['sectionId'][] = $group->id;
                        $deleteFromAlgolia = false;
                    }
                }

                // check that this user is NOT in Algolia any more
                // if their user group used to match, but it's been changed
                // and they need to be removed...
                // this is where we send a quick message to Algolia to purge out their record

                if ($deleteFromAlgolia && $processRecords) {

                    $elementData = [];
                    $elementData['index'] = $recordUpdate['index'] = AlgoliaSync::$plugin->algoliaSyncService->getAlgoliaIndex($element);
                    $elementData['attributes'] = [];
                    $elementData['attributes']['objectID'] = $element->id;

                    AlgoliaSync::$plugin->algoliaSyncService->algoliaSyncRecord('delete', $elementData);
                }

                break;
        }

        return $info;
    }

    public function getAlgoliaIndex($element) {
        $returnIndex = [];

        if (getenv('ALGOLIA_INDEX_NAME') !== false) {
            $returnIndex[] = getenv('ALGOLIA_INDEX_NAME');
        }
        else {
            $eventInfo = AlgoliaSync::$plugin->algoliaSyncService->getEventElementInfo($element, false);

            foreach ($eventInfo['sectionHandle'] AS $handle) {
                $returnIndex[] = strtolower(getenv('ENVIRONMENT')).'_'.$eventInfo['type'].'_'.$handle;
            }
        }
        return $returnIndex;
    }
}
