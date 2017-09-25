<?php
namespace futuretek\rbac;

use futuretek\rbac\models\AuthAssignment;
use futuretek\rbac\models\AuthItem;
use futuretek\rbac\models\AuthItemChild;
use futuretek\rbac\models\AuthRule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;
use Yii;
use yii\console\Controller;
use yii\db\Transaction;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;
use yii\helpers\FileHelper;

/**
 * Class RbacController
 *
 * RBAC initialization controller
 *
 * @package app\commands
 * @author  Petr Leo Compel <petr.compel@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
class RbacController extends Controller
{
    /**
     * @var AuthItem[]
     */
    private $_permissions = [];

    /**
     * @var AuthItem[]
     */
    private $_roles = [];

    /**
     * @var Transaction
     */
    private $_transaction;

    /**
     * Usage help
     */
    public function actionIndex()
    {
        $this->stdout("This action creates RBAC definitions from available controllers.\n\n");
        $this->stdout("rbac/init\t\tInitialize RBAC database, builds permissions and default roles.\n");
        $this->stdout("rbac/export\t\tExport permissions to file.\n");
        $this->stdout("rbac/import\t\tImport permissions from file based on application language.\n\n");
    }

    /**
     * RBAC init action
     *
     * @throws \yii\db\Exception
     * @throws \Exception
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\NotSupportedException
     * @throws \futuretek\yii\shared\FtsException
     * @throws \futuretek\yii\shared\ModelSaveException
     */
    public function actionInit()
    {
        $this->_transaction = Yii::$app->db->beginTransaction();
        $this->color = true;
        $this->stdout("Task \"Init RBAC rights\" started.\n", Console::FG_PURPLE);
        $this->stdout(str_repeat('-', 40) . "\n\n");

        //Config
        $this->stdout("Loading config...\n", Console::FG_GREEN);
        /** @var array[] $config */
        $config = $this->loadFromFile(Yii::$app->basePath . '/rbac/config.php');

        //Clear data
        if (YII_DEBUG && $this->interactive && $this->confirm('Do you want to clear all RBAC data?')) {
            $this->clearData();
        }

        $deletePerms = YII_DEBUG && $this->confirm('Do you want to remove non-existing permissions?');

        //Roles
        $this->stdout("Syncing roles...\n", Console::FG_GREEN);
        $this->syncRoles($config['roles']);

        //Permissions
        $this->stdout("Syncing permissions...\n", Console::FG_GREEN);
        $this->syncPermissions($config['specialPermissions'], $deletePerms);

        //Permissions - Admin
        $this->stdout("Adding all permissions to role \"admin\"...\n", Console::FG_GREEN);
        foreach ($this->_permissions as $perm) {
            $this->_roles['admin']->addPermission($perm->name);
        }

        //Permissions - Manager
        foreach ($config['permissions'] as $key => $value) {
            $this->stdout("Adding permissions to role \"{$key}\"...\n", Console::FG_GREEN);
            $this->_addToRole($this->_roles[$key], $value);
        }

        //User roles
        $this->checkAssignment('admin', 'admin');

        if ($this->_transaction !== null) {
            $this->_transaction->commit();
        }

        $this->stdout("Done.\n\n", Console::FG_BLUE);

        $this->stdout('If you want to transfer generated RBAC to other environments, you should run ', Console::FG_PURPLE);
        $this->stdout('yii rbac/export', Console::FG_YELLOW);
        $this->stdout(" and commit changed files to GIT.\n", Console::FG_PURPLE);
    }

    /**
     * RBAC export action
     *
     * @throws \yii\db\Exception
     * @throws \Exception
     * @throws \yii\base\InvalidParamException
     * @throws \yii\console\Exception
     */
    public function actionExport()
    {
        $this->color = true;
        $this->stdout("Task \"Export RBAC rights\" started.\n", Console::FG_PURPLE);
        $this->stdout(str_repeat('-', 40) . "\n\n");

        if (!@mkdir(Yii::$app->basePath . '/rbac') && !is_dir(Yii::$app->basePath . '/rbac')) {
            $this->error('Error while creating export directory.');
        }

        //Get exported languages
        $languages = FileHelper::findFiles(Yii::$app->basePath . '/rbac', ['only' => ['/auth-roles.*'], 'recursive' => false]);
        array_walk($languages, function (&$value, $key) use ($languages) {
            $part = explode('.', $value);
            if (is_array($part)) {
                $value = end($part);
            } else {
                unset($languages[$key]);
            }
        });

        //Add default languages
        $languages[] = substr(Yii::$app->language, 0, 2);
        $languages[] = substr(Yii::$app->sourceLanguage, 0, 2);
        $languages = array_unique($languages);

        //AuthItem
        $this->stdout("Loading previously exported files...\n", Console::FG_GREEN);
        /** @var array[][] $oldData */
        $oldData = ['roles' => [], 'permissions' => []];
        foreach (['roles', 'permissions'] as $entity) {
            foreach ($languages as $lang) {
                $oldData[$entity][$lang] = $this->loadFromFile(Yii::$app->basePath . "/rbac/auth-{$entity}.{$lang}", false);
            }
        }

        //New data to export
        $this->stdout("Loading export data...\n", Console::FG_GREEN);
        /** @var array[][] $authItems */
        $authItems = [
            'roles' => AuthItem::find()->where(['type' => AuthItem::TYPE_ROLE])->orderBy(['type' => SORT_ASC, 'name' => SORT_ASC])->asArray()->all(),
            'permissions' => AuthItem::find()->where(['type' => AuthItem::TYPE_PERMISSION])->orderBy(['type' => SORT_ASC, 'name' => SORT_ASC])->asArray()->all(),
        ];
        $authItemChildren = AuthItemChild::find()->orderBy(['parent' => SORT_ASC, 'child' => SORT_ASC])->asArray()->all();
        $authRules = AuthRule::find()->asArray()->all();

        //Data merge
        /** @var array[][] $newData */
        $newData = ['roles' => [], 'permissions' => []];
        $this->stdout("Merging data...\n", Console::FG_GREEN);
        foreach (['roles', 'permissions'] as $entity) {
            foreach ($oldData[$entity] as $lang => $data) {
                $newData[$entity][$lang] = [];
                $map = array_flip(ArrayHelper::getColumn($data, 'name'));
                foreach ($authItems[$entity] as $item) {
                    if (array_key_exists($item['name'], $map) && array_key_exists('description', $data[$map[$item['name']]])) {
                        $item['description'] = $data[$map[$item['name']]]['description'];
                    }
                    $newData[$entity][$lang][] = $item;
                }
            }
        }

        //Save
        $this->stdout("Saving files...\n", Console::FG_GREEN);
        foreach (['roles', 'permissions'] as $entity) {
            foreach ($newData[$entity] as $lang => $data) {
                $this->saveToFile(Yii::$app->basePath . "/rbac/auth-{$entity}.{$lang}", $data);
            }
        }
        $this->saveToFile(Yii::$app->basePath . '/rbac/auth-item-child', $authItemChildren);
        $this->saveToFile(Yii::$app->basePath . '/rbac/auth-rule', $authRules);

        $this->stdout("Done.\n\n", Console::FG_BLUE);
    }

    /**
     * RBAC import action
     *
     * @throws \yii\db\Exception
     * @throws \Exception
     * @throws \yii\base\InvalidParamException
     * @throws \yii\console\Exception
     * @throws \yii\base\NotSupportedException
     */
    public function actionImport()
    {
        $this->_transaction = Yii::$app->db->beginTransaction();
        $this->color = true;
        $this->stdout("Task \"Import RBAC rights\" started.\n", Console::FG_PURPLE);
        $this->stdout(str_repeat('-', 40) . "\n\n");

        $lang = substr(Yii::$app->language, 0, 2);

        //Roles
        /** @var array $authItemRoles */
        $this->stdout("Importing roles...\n", Console::FG_GREEN);
        $authItemRoles = $this->loadFromFile(Yii::$app->basePath . '/rbac/auth-roles.' . $lang);
        $oldRoles = array_flip(AuthItem::find()->select(['name'])->where(['type' => AuthItem::TYPE_ROLE])->asArray()->column());
        $oldSysRoles = array_flip(AuthItem::find()->select(['name'])->where(['type' => AuthItem::TYPE_ROLE, 'system' => true])->asArray()->column());
        if (is_array($authItemRoles)) {
            foreach ($authItemRoles as $item) {
                unset($oldRoles[$item['name']]);
                $obj = AuthItem::findOne($item['name']);
                if ($obj === null) {
                    $obj = new AuthItem($item);
                } else {
                    $obj->load($item);
                }
                $obj->isNewRecord ? $this->stdout("\tCreating role {$obj->name}\n") : $this->stdout("\tUpdating role {$obj->name}\n");
                if (!$obj->save()) {
                    $this->error("Error while saving role {$obj->name}: " . implode(' ', array_values($obj->getFirstErrors())));
                }
            }
            //Delete non-existing roles
            foreach (array_flip($oldRoles) as $item) {
                if (array_key_exists($item, $oldSysRoles)) {
                    $this->stdout("\tRemoving role {$item}\n");
                    AuthItem::deleteAll(['type' => AuthItem::TYPE_ROLE, 'name' => $item]);
                }
            }
        } else {
            $this->error('Roles were not loaded as array.');
        }
        unset($oldRoles, $oldSysRoles, $authItemRoles);

        //Permissions
        $this->stdout("Importing permissions...\n", Console::FG_GREEN);
        /** @var array $authItemPermissions */
        $authItemPermissions = $this->loadFromFile(Yii::$app->basePath . '/rbac/auth-permissions.' . $lang);
        $oldPermissions = array_flip(AuthItem::find()->select(['name'])->where(['type' => AuthItem::TYPE_PERMISSION])->asArray()->column());
        if (is_array($authItemPermissions)) {
            foreach ($authItemPermissions as $item) {
                unset($oldPermissions[$item['name']]);
                $obj = AuthItem::findOne($item['name']);
                if ($obj === null) {
                    $obj = new AuthItem($item);
                } else {
                    $obj->load($item);
                }
                $obj->isNewRecord ? $this->stdout("\tCreating permission {$obj->name}\n") : $this->stdout("\tUpdating permission {$obj->name}\n");
                if (!$obj->save()) {
                    $this->error("Error while saving permission {$obj->name}: " . implode(' ', array_values($obj->getFirstErrors())));
                }
            }
            //Delete non-existing permissions
            foreach (array_flip($oldPermissions) as $item) {
                $this->stdout("\tRemoving permission {$item}\n");
                AuthItem::deleteAll(['type' => AuthItem::TYPE_PERMISSION, 'name' => $item]);
            }
        } else {
            $this->error('Permissions were not loaded as array.');
        }
        unset($oldPermissions, $authItemPermissions);

        $this->stdout("Importing permission-role mappings...\n", Console::FG_GREEN);
        //Auth item children
        /** @var array $authItemChild */
        $authItemChild = $this->loadFromFile(Yii::$app->basePath . '/rbac/auth-item-child');
        if (is_array($authItemChild)) {
            foreach ($authItemChild as $item) {
                if (!AuthItemChild::find()->where(['parent' => $item['parent'], 'child' => $item['child']])->exists()) {
                    $obj = new AuthItemChild($item);
                    $this->stdout("\tCreating mapping {$obj->parent}-{$obj->child}\n");
                    if (!$obj->save()) {
                        $this->error("Error while saving entity AuthItemChild record {$obj->parent}-{$obj->child}: " . implode(' ', array_values($obj->getFirstErrors())));
                    }
                }
            }
        } else {
            $this->error('Auth item children were not loaded as array.');
        }
        unset($authItemChild);

        //Auth rules
        $this->stdout("Importing rules...\n", Console::FG_GREEN);
        /** @var array $authRule */
        $authRule = $this->loadFromFile(Yii::$app->basePath . '/rbac/auth-rule');
        if (is_array($authRule)) {
            foreach ($authRule as $item) {
                $obj = AuthRule::findOne($item['name']);
                if ($obj === null) {
                    $obj = new AuthRule($item);
                } else {
                    $obj->load($item);
                }
                $obj->isNewRecord ? $this->stdout("\tCreating rule {$obj->name}\n") : $this->stdout("\tUpdating rule {$obj->name}\n");
                if (!$obj->save()) {
                    $this->error("Error while saving rule {$obj->name}: " . implode(' ', array_values($obj->getFirstErrors())));
                }
            }
        } else {
            $this->error('Rules were not loaded as array.');
        }
        unset($authRule);

        //Ensure admin auth assignment
        $this->stdout("Ensuring admin is still admin...\n", Console::FG_GREEN);
        $this->checkAssignment('admin', 'admin');

        //Delete auth item children for non-existing auth items
        $this->stdout("Removing obsolete role-permission mappings...\n", Console::FG_GREEN);
        $roles = AuthItem::find()->select(['name'])->distinct()->where(['type' => AuthItem::TYPE_ROLE])->asArray()->column();
        $permissions = AuthItem::find()->select(['name'])->distinct()->where(['type' => AuthItem::TYPE_PERMISSION])->asArray()->column();
        AuthItemChild::deleteAll(['or', ['not in', 'parent', $roles], ['not in', 'child', $permissions]]);

        //Delete assignments for non-existing roles
        $this->stdout("Removing obsolete user to role assignments...\n", Console::FG_GREEN);
        AuthAssignment::deleteAll(['not in', 'item_name', $roles]);

        if ($this->_transaction !== null) {
            $this->_transaction->commit();
        }

        $this->stdout("Done.\n\n", Console::FG_BLUE);
    }

    /**
     * Sync roles (roles not in DB will be created and obsolete roles in DB will be deleted)
     *
     * @param array $roles Role definition to sync
     * @throws \yii\db\Exception
     */
    protected function syncRoles(array $roles)
    {
        $oldRoles = array_flip(AuthItem::find()->select(['name'])->where(['type' => AuthItem::TYPE_ROLE])->asArray()->column());

        foreach ($roles as $item) {
            if (!$this->checkIfContain($item, ['name', 'description', 'system'])) {
                $this->error('Detected bad role definition.');
            }
            if (array_key_exists($item['name'], $oldRoles)) {
                $this->stdout("\tRole {$item['name']} already exist. Skipping.\n", Console::FG_YELLOW);
                $role = AuthItem::find()->where(['type' => AuthItem::TYPE_ROLE, 'name' => $item['name']])->one();
            } else {
                $this->stdout("\tCreating role {$item['name']}\n");
                $role = new AuthItem();
                $role->name = $item['name'];
                $role->type = AuthItem::TYPE_ROLE;
                $role->description = $item['description'];
                $role->rule_name = array_key_exists('ruleName', $item) ? $item['ruleName'] : null;
                $role->data = array_key_exists('data', $item) ? $item['data'] : null;
                $role->system = $item['system'];
                if (!$role->save()) {
                    $this->error("Error while saving role {$item['name']}: " . implode(' ', array_values($role->getFirstErrors())));
                }
            }
            unset($oldRoles[$item['name']]);
            $this->_roles[$item['name']] = $role;
        }

        //Delete non-existing roles
        foreach (array_flip($oldRoles) as $item) {
            $this->stdout("\tRemoving role {$item}\n");
            AuthItem::deleteAll(['type' => AuthItem::TYPE_ROLE, 'name' => $item]);
        }
    }

    /**
     * Sync permissions (permissions not in DB will be created and obsolete permissions in DB will be deleted)
     *
     * @param array $specialPermissions Special permissions
     * @param bool $deletePerms Delete non existing permissions
     * @throws \yii\base\InvalidParamException
     * @throws \yii\db\Exception
     */
    protected function syncPermissions($specialPermissions, $deletePerms)
    {
        $oldPermissions = array_flip(AuthItem::find()->select(['name'])->where(['type' => AuthItem::TYPE_PERMISSION])->asArray()->column());

        $permissions = $this->buildPermissionList();
        $permissions = array_merge($permissions, $specialPermissions);

        foreach ($permissions as $item) {
            if (!$this->checkIfContain($item, ['name', 'description', 'category'])) {
                $this->error('Detected bad permission definition.');
            }
            if (array_key_exists($item['name'], $oldPermissions)) {
                $this->stdout("\tPermission {$item['name']} already exist. Skipping.\n", Console::FG_YELLOW);
                $permission = AuthItem::find()->where(['type' => AuthItem::TYPE_PERMISSION, 'name' => $item['name']])->one();
            } else {
                $this->stdout("\tCreating permission {$item['name']}\n");
                $permission = new AuthItem();
                $permission->name = $item['name'];
                $permission->type = AuthItem::TYPE_PERMISSION;
                $permission->description = $item['description'];
                $permission->category = $item['category'];
                $permission->rule_name = array_key_exists('ruleName', $item) ? $item['ruleName'] : null;
                $permission->data = array_key_exists('data', $item) ? $item['data'] : null;
                $permission->system = array_key_exists('system', $item) ? $item['system'] : false;
                if (!$permission->save()) {
                    $this->error("Error while saving permission {$item['name']}: " . implode(' ', array_values($permission->getFirstErrors())));
                }
            }
            unset($oldPermissions[$item['name']]);
            $this->_permissions[$item['name']] = $permission;
        }

        if ($deletePerms) {
            //Delete non-existing permissions
            foreach (array_flip($oldPermissions) as $item) {
                $this->stdout("\tRemoving permission {$item}\n");
                AuthItem::deleteAll(['type' => AuthItem::TYPE_PERMISSION, 'name' => $item]);
            }
        }
    }

    /**
     * Check if array contains specified elements
     *
     * @param array $target Checked array
     * @param array $keys Keys to check
     * @return bool
     */
    protected function checkIfContain(array $target, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $target)) {
                $this->stdout("\tItem does not contain required element {$key}. Skipping", Console::FG_YELLOW);

                return false;
            }
        }

        return true;
    }


    /**
     * Check if user has an assignment and if not, optionally create one
     *
     * @param int $userId User ID
     * @param string $role Role name
     * @param bool $add Add this assignment if not exist
     * @return bool
     * @throws \yii\db\Exception
     */
    protected function checkAssignment($userId, $role, $add = false)
    {
        $result = AuthAssignment::find()->where(['user_id' => $userId, 'item_name' => $role])->exists();

        if (!$add || $result) {
            return $result;
        }

        $assignment = new AuthAssignment();
        $assignment->user_id = $userId;
        $assignment->item_name = $role;
        if (!$assignment->save()) {
            $this->error('Error while saving admin auth assignment: ' . implode(' ', array_values($assignment->getFirstErrors())));
        }

        return true;
    }

    /**
     * Add rules to role
     *
     * Example
     * ['ControllerName' => ['actionName1', 'actionName2']]
     * or
     * ['ControllerName' => 'all']
     *
     * @param AuthItem $role Role
     * @param array $permissions Role permissions array
     *
     * @throws \Exception
     */
    private function _addToRole($role, array $permissions)
    {
        /** @var array $actions */
        foreach ($permissions as $controllerName => $actions) {
            $controllerName = ucfirst($controllerName);
            if (is_array($actions)) {
                foreach ($actions as $action) {
                    $action = ucfirst($action);
                    if (!array_key_exists($controllerName . $action, $this->_permissions)) {
                        $this->error("\tPermission {$controllerName}{$action} for role {$role->name} not found.\n");
                    }
                    $this->_permissions[$controllerName . $action]->addPermissionToRole($role->name);
                }
            } elseif ($actions === 'all') {
                /** @var AuthItem[] $perms */
                $perms = AuthItem::find()->where(['type' => AuthItem::TYPE_PERMISSION, 'category' => $controllerName])->all();
                if (0 === count($perms)) {
                    $this->stdout("\tWarning: Permission for controller {$controllerName} and role {$role->name} specified as \"all\" but no actions have been found.\n", Console::FG_RED);
                }
                foreach ($perms as $perm) {
                    $perm->addPermissionToRole($role->name);
                }
            } else {
                $this->error("\tWrong permission value for controller {$controllerName} and role {$role->name}.\n");
            }
        }
    }

    /**
     * Get array of all controllers actions
     *
     * @return array
     * @throws \yii\base\InvalidParamException
     */
    protected function buildPermissionList()
    {
        $result = [];

        $directory = new RecursiveDirectoryIterator(Yii::getAlias('@app/controllers'));
        $iterator = new RecursiveIteratorIterator($directory);
        $regex = new RegexIterator($iterator, '/^.+Controller\.php$/i', RecursiveRegexIterator::GET_MATCH);

        foreach ($regex as $name => $object) {
            $fullClassName = '\app' . str_replace('/', '\\', substr($name, strlen(Yii::$app->basePath), -4));
            $array = explode('\\', $fullClassName);
            $className = end($array);
            $controllerName = substr($className, 0, -10);
            $ref = new \ReflectionClass($fullClassName);
            $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (0 !== strpos($method->getName(), 'action')) {
                    continue;
                }
                if ($method->getName() === 'actions') {
                    //Parse actions
                    $content = file_get_contents($name);
                    if (!preg_match('/actions\(\).*?{(.*?)}/s', $content, $matches)) {
                        continue;
                    }

                    /** @var array $aMethods */
                    $aMethods = eval($matches[1]);
                    if (!is_array($aMethods)) {
                        continue;
                    }
                    foreach ($aMethods as &$aMethod) {
                        if (is_array($aMethod)) {
                            $aMethod = $aMethod['class'];
                        }
                        $parts = explode('\\', $aMethod);
                        $aMethod = end($parts);
                        if (strpos($aMethod, 'Action') !== false) {
                            $aMethod = substr($aMethod, 0, -6);
                        }
                        $result[] = [
                            'category' => $controllerName,
                            'action' => $aMethod,
                            'name' => $controllerName . $aMethod,
                            'description' => 'Allow to ' . strtolower($aMethod) . " the {$controllerName}.",
                        ];
                    }
                    unset($aMethod);
                } else {
                    $aMethod = substr($method->getName(), 6);
                    $result[] = [
                        'category' => $controllerName,
                        'action' => $aMethod,
                        'name' => $controllerName . $aMethod,
                        'description' => 'Allow to ' . strtolower($aMethod) . " the {$controllerName}.",
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Clear all RBAC data
     *
     * @throws \yii\db\Exception
     * @throws \yii\base\NotSupportedException
     */
    protected function clearData()
    {
        $this->stdout("Truncating auth tables...\n", Console::FG_GREEN);
        Yii::$app->db->createCommand()->checkIntegrity(false)->execute();
        Yii::$app->db->createCommand()->truncateTable('auth_rule')->execute();
        Yii::$app->db->createCommand()->truncateTable('auth_item')->execute();
        Yii::$app->db->createCommand()->truncateTable('auth_item_child')->execute();
        Yii::$app->db->createCommand()->truncateTable('auth_assignment')->execute();
        Yii::$app->db->createCommand()->checkIntegrity(true)->execute();
    }

    /**
     * Save data to export file
     *
     * @param string $fileName File name
     * @param array $data Input data
     * @throws \yii\db\Exception
     */
    protected function saveToFile($fileName, array $data)
    {
        $this->stdout("Exporting file \"{$fileName}\"...\n", Console::FG_GREEN);
        if (!file_put_contents($fileName, '<?php return ' . var_export($data, true) . ';')) {
            $this->error("Error while exporting file {$fileName}.");
        }
    }

    /**
     * Load data from export file and returns it
     *
     * @param string $fileName File name
     * @param bool $fail Fail when file not found
     * @return mixed
     * @throws \yii\db\Exception
     */
    protected function loadFromFile($fileName, $fail = true)
    {
        if (!file_exists($fileName)) {
            if ($fail) {
                $this->error("File {$fileName} cannot be found.");
            } else {
                return [];
            }
        }
        $this->stdout("Importing file \"{$fileName}\"...\n", Console::FG_GREEN);

        /** @noinspection PhpIncludeInspection */
        return require $fileName;
    }

    /**
     * Display error and exit
     *
     * @param string $message Error message
     * @throws \yii\db\Exception
     */
    protected function error($message)
    {
        if ($this->_transaction !== null) {
            $this->stdout("Reverting changes...\n", Console::FG_RED);
            $this->_transaction->rollBack();
        }

        die($this->stderr($message, Console::FG_RED, Console::UNDERLINE));
    }
}